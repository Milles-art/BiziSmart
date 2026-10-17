<?php
namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{Business, Device, OfflineLease, OfflineOperation, PersonalAccessToken, Product, ProductBatch, ProductPackaging, Sale, SalePayment, TillSession, User};
use App\Support\{ExactMath, Money, OfflineProof, Quantity};
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\{AuthenticationException, Access\AuthorizationException};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OfflineSaleService
{
    public function __construct(private TenantContext $context, private StockService $stock, private TillService $tills, private TillAccessService $access) {}

    public function issue(User $original): array {
        return DB::transaction(function()use($original){
            [$actor,$device]=$this->session($original);
            $shop=Business::whereKey($this->context->requireId())->where('is_active',true)->lockForUpdate()->firstOrFail();
            $till=TillSession::with(['branch','cashier'])->where('cashier_id',$actor->id)->where('branch_id',$actor->branch_id)->where('status','open')->lockForUpdate()->first();
            if(!$till || !$this->access->canManage($actor,$till->branch,$till->cashier))throw new ApiException('TILL_REQUIRED','Open your own till before preparing offline selling.',409);
            $issued=CarbonImmutable::now()->startOfSecond(); $secret=bin2hex(random_bytes(32)); $catalogue=[];
            $products=Product::where('is_active',true)->orderBy('id')->limit(501)->lockForUpdate()->get();
            foreach($products->take(500)as$product){
                $batches=ProductBatch::where('product_id',$product->id)->where('qty_remaining_atoms','>',0)->where('is_quarantined',false)->where('is_recalled',false)
                    ->where(fn($q)=>$q->whereNull('expiry_date')->orWhereDate('expiry_date','>=',now('Africa/Dar_es_Salaam')->toDateString()))->orderBy('id')->lockForUpdate()->get();
                if($batches->isEmpty())continue;
                $stock=0;foreach($batches as$batch)$stock=ExactMath::add($stock,$batch->qty_remaining_atoms);
                $packs=ProductPackaging::where('product_id',$product->id)->where('is_active',true)->whereNull('archived_at')->orderBy('id')->lockForUpdate()->get();
                foreach([null,...$packs->all()]as$pack){
                    $snapshot=['snapshot_id'=>(string)Str::uuid(),'product_id'=>$product->publicId(),'name'=>$product->name,'barcode'=>$pack?->barcode??$product->barcode,
                        'quantity_type'=>$product->quantity_type,'unit'=>$product->unit,'packaging_id'=>$pack?->publicId(),'packaging_name'=>$pack?->name,'packaging_version'=>$pack?->version,
                        'conversion_atoms'=>(string)($pack?->conversion_atoms??($product->quantity_type==='weight'?1000:1)),
                        'unit_price'=>app(PricingService::class)->effectivePrice($product,$pack),'stock'=>Quantity::format($stock,$product->quantity_type==='weight')];
                    $catalogue[]=['snapshot'=>$snapshot,'product_key'=>$product->id,'packaging_key'=>$pack?->id,'batch_keys'=>$batches->modelKeys()];
                }
            }
            $lease=OfflineLease::create(['user_id'=>$actor->id,'device_id'=>$device->id,'till_session_id'=>$till->id,'secret'=>$secret,'catalogue'=>$catalogue,
                'vat_rate'=>$shop->vat_rate,'issued_at'=>$issued,'expires_at'=>$issued->addHours(24)]);
            return ['lease_id'=>$lease->publicId(),'device_uid'=>$device->device_uid,'issued_at'=>$issued->toISOString(),'expires_at'=>$lease->expires_at->toISOString(),
                'secret'=>$secret,'shop_name'=>$shop->name,'cashier_id'=>$actor->publicId(),'cashier_name'=>$actor->name,'branch_name'=>$till->branch->name,'vat_rate'=>$lease->vat_rate,'truncated'=>$products->count()>500,
                'catalogue'=>array_map(fn($entry)=>[...$entry['snapshot'],'proof'=>OfflineProof::snapshot($lease->publicId(),$entry['snapshot'])],$catalogue),
                'automatic_offers_enabled'=>false];
        },3);
    }

    public function sync(array $input,string $signature): array {
        // Tenant comes exclusively from the hostname resolver; the persisted lease supplies actor/device identity.
        $candidate=(new OfflineLease)->resolveRouteBinding($input['lease_id']);
        if(!$candidate || !OfflineProof::verify($input,$candidate->secret,$signature))throw new AuthenticationException;
        return DB::transaction(function()use($candidate,$input){
            $actor=User::withTrashed()->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $shop=Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
            $device=Device::whereKey($candidate->device_id)->lockForUpdate()->firstOrFail();
            $lease=OfflineLease::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $fingerprint=SupervisorApprovalService::snapshotHash($input);
            $old=OfflineOperation::where('operation_id',strtolower($input['operation_id']))->lockForUpdate()->first();
            if($old){
                if((int)$old->lease_id!==(int)$lease->id || !hash_equals($old->request_fingerprint,$fingerprint))throw new ApiException('OPERATION_ID_REUSED','This offline sale reference has different details.',409);
                return [...$this->publicResult($old->result),'replayed'=>true];
            }
            $till=TillSession::with(['branch','cashier'])->whereKey($lease->till_session_id)->lockForUpdate()->firstOrFail();
            $conflicts=[];
            if($device->device_uid!==$input['device_uid'])throw new AuthenticationException;
            if($device->status!=='approved')$conflicts[]='DEVICE_REVOKED';
            if($lease->revoked_at || !$lease->expires_at->isFuture())$conflicts[]='LEASE_EXPIRED';
            if(!$shop->is_active || !$actor->is_active || $actor->trashed() || $actor->must_change_pin || !$actor->can('sell'))$conflicts[]='STAFF_REVOKED';
            if($till->status!=='open')$conflicts[]='TILL_CLOSED';
            if((int)$actor->branch_id!==(int)$till->branch_id || !$till->branch->is_active)$conflicts[]='BRANCH_CHANGED';
            if(!$this->access->canManage($actor,$till->branch,$till->cashier))$conflicts[]='STAFF_REVOKED';
            $captured=CarbonImmutable::parse($input['captured_at']);
            if($captured->lt($lease->issued_at) || $captured->gt($lease->expires_at) || $captured->gt(CarbonImmutable::now()->addMinutes(5)))$conflicts[]='CAPTURE_TIME_INVALID';
            try{$review=$this->quote($lease,$input);}catch(\InvalidArgumentException|\OverflowException $e){$conflicts[]='PAYLOAD_INVALID';$review=null;}
            $operation=OfflineOperation::create(['lease_id'=>$lease->id,'user_id'=>$actor->id,'device_id'=>$device->id,'operation_id'=>strtolower($input['operation_id']),
                'request_fingerprint'=>$fingerprint,'payload'=>$input,'status'=>'quarantined','conflicts'=>$conflicts,'captured_at'=>$captured]);
            if(!$conflicts && $review){
                try{return $this->commit($operation,$lease,$actor,$device,$shop,$till,$review,null);}
                catch(\InvalidArgumentException|\OverflowException $e){$conflicts[]='ARITHMETIC_LIMIT';}
            }
            $operation->conflicts=$conflicts;$operation->result=['id'=>$operation->publicId(),'operation_id'=>$operation->operation_id,'status'=>'quarantined','conflicts'=>$conflicts,'sale'=>null,'replayed'=>false];$operation->save();
            activity('offline')->performedOn($operation)->causedBy($actor)->event('offline_sale_quarantined')->withProperties(['conflicts'=>$conflicts,'operation_id'=>$operation->operation_id])->log('Offline sale requires owner review');
            return $operation->result;
        },3);
    }

    public function pending(User $owner): array {
        [$actor]=$this->session($owner,true);
        return OfflineOperation::where('status','quarantined')->orderBy('created_at')->limit(200)->get()->map(fn($op)=>[
            'id'=>$op->publicId(),'operation_id'=>$op->operation_id,'captured_at'=>$op->captured_at?->toISOString(),'conflicts'=>$op->conflicts,
            'cashier_name'=>User::withTrashed()->find($op->user_id)?->name,'created_at'=>$op->created_at->toISOString()])->all();
    }

    public function conflicts(User $original,array $filters): array {
        return DB::transaction(function()use($original,$filters){
            $this->session($original,true);
            $page=OfflineOperation::where('status','committed_with_conflict')->orderByDesc('id')->paginate($filters['per_page']??25);
            return ['sales'=>array_map(function($operation){
                $sale=Sale::findOrFail($operation->sale_id);
                return ['id'=>$operation->publicId(),'sale_id'=>$sale->publicId(),'receipt_number'=>$sale->receipt_number,
                    'total'=>$sale->total,'captured_at'=>$operation->captured_at->toISOString(),'conflicts'=>$operation->conflicts,
                    'cashier_name'=>User::withTrashed()->find($operation->user_id)?->name,'owner_reconciled'=>$operation->resolved_at!==null];
            },$page->items()),'meta'=>['page'=>$page->currentPage(),'per_page'=>$page->perPage(),'total'=>$page->total()]];
        },3);
    }

    public function reviewReconciliation(User $original,OfflineOperation $operation,array $input): array {
        return DB::transaction(function()use($original,$operation,$input){
            [$owner]=$this->session($original,true); $operation->refreshForUpdate();
            $lease=OfflineLease::whereKey($operation->lease_id)->lockForUpdate()->firstOrFail();
            $till=$this->reconciliationTill($owner,$input['till_id']);
            if($operation->status!=='quarantined')throw new ApiException('OFFLINE_ALREADY_RESOLVED','This offline sale is already reconciled.',409);
            try{$quote=$this->quote($lease,$operation->payload);}catch(\InvalidArgumentException|\OverflowException $e){throw new ApiException('OFFLINE_PAYLOAD_INVALID','This payload cannot be financially reconciled without a corrected, audited record.',409);}
            $snapshot=['operation_id'=>$input['operation_id'],'offline_id'=>$operation->publicId(),'request_fingerprint'=>$operation->request_fingerprint,
                'branch_id'=>$till->branch->publicId(),'till_id'=>$till->publicId(),'till_revision'=>$till->revision,'total'=>$quote['total'],
                'captured_at'=>$operation->captured_at->toISOString(),'conflicts'=>$operation->conflicts,'reason'=>trim($input['reason'])];
            return [...$snapshot,'review_hash'=>SupervisorApprovalService::snapshotHash($snapshot)];
        },3);
    }

    public function reconcile(User $original,OfflineOperation $operation,array $input): array {
        $approval=\App\Models\SupervisorApproval::where('token_hash',hash('sha256',$input['approval_token']))->first();
        return DB::transaction(function()use($original,$operation,$input,$approval){
            User::withTrashed()->whereIn('id',array_unique(array_filter([$original->id,$operation->user_id,$approval?->approver_id])))->orderBy('id')->lockForUpdate()->get();
            [$owner]=$this->session($original,true); $operation->refreshForUpdate();
            $fingerprint=SupervisorApprovalService::snapshotHash($this->reconciliationBody($input));
            if($operation->resolved_at){
                if(($operation->result['reconciliation_operation_id']??null)!==strtolower($input['operation_id']) || ($operation->result['reconciliation_fingerprint']??null)!==$fingerprint || (int)$operation->resolved_by!==(int)$owner->id)throw new ApiException('OPERATION_ID_REUSED','This reconciliation already has different details.',409);
                return [...$this->publicResult($operation->result),'replayed'=>true];
            }
            $review=$this->reviewReconciliation($owner,$operation,$input);
            if(!hash_equals($review['review_hash'],$input['review_hash']))throw new ApiException('OFFLINE_REVIEW_CHANGED','The reconciliation till or details changed. Review again.',409);
            $lease=OfflineLease::whereKey($operation->lease_id)->lockForUpdate()->firstOrFail();
            $actor=User::withTrashed()->whereKey($lease->user_id)->lockForUpdate()->firstOrFail();
            $device=Device::whereKey($lease->device_id)->lockForUpdate()->firstOrFail();
            $shop=Business::whereKey($this->context->requireId())->where('is_active',true)->lockForUpdate()->firstOrFail();
            $till=$this->reconciliationTill($owner,$input['till_id']);
            $snapshot=$review;unset($snapshot['review_hash']);
            return app(SupervisorApprovalService::class)->execute($owner,$input['approval_token'],'manage_settings',$input['operation_id'],$snapshot,function(User $approver)use($operation,$lease,$actor,$device,$shop,$till,$owner,$input,$fingerprint){
                if(!$approver->hasRole('owner'))throw new AuthorizationException;
                $quote=$this->quote($lease,$operation->payload);
                $result=$this->commit($operation,$lease,$actor,$device,$shop,$till,$quote,$owner);
                $operation->resolved_by=$owner->id;$operation->resolved_at=now();$operation->resolution_reason=trim($input['reason']);
                $operation->result=[...$result,'reconciliation_operation_id'=>strtolower($input['operation_id']),'reconciliation_fingerprint'=>$fingerprint];$operation->save();
                return $this->publicResult($operation->result);
            });
        },3);
    }

    private function quote(OfflineLease $lease,array $input): array {
        $catalogue=[];foreach($lease->catalogue as$entry)$catalogue[$entry['snapshot']['snapshot_id']]=$entry;
        $lines=[];$total=0;
        foreach($input['items']as$item){
            $entry=$catalogue[$item['snapshot_id']]??null;if(!$entry)throw new \InvalidArgumentException('Unknown cached product snapshot.');
            $s=$entry['snapshot'];if(!hash_equals(OfflineProof::snapshot($lease->publicId(),$s),$item['proof']))throw new \InvalidArgumentException('Changed cached product snapshot.');
            $weighted=$s['quantity_type']==='weight';$qtyAtoms=Quantity::parse($item['qty'],$weighted&&!$s['packaging_id']);if($qtyAtoms<=0)throw new \InvalidArgumentException('Enter positive exact quantities.');
            $atoms=$s['packaging_id']?Quantity::packageAtoms($item['qty'],(int)$s['conversion_atoms']):$qtyAtoms;
            $charge=Money::lineTotal($qtyAtoms,$s['unit_price'],$weighted&&!$s['packaging_id']);$total=Money::amount(ExactMath::add($total,$charge));
            $lines[]=[...$s,'qty'=>Quantity::format($qtyAtoms,$weighted&&!$s['packaging_id']),'base_qty'=>Quantity::format($atoms,$weighted),'discount'=>0,'line_discount'=>0,'cart_discount'=>0,'line_total'=>$charge,
                '_product_key'=>$entry['product_key'],'_packaging_key'=>$entry['packaging_key'],'_batch_keys'=>$entry['batch_keys'],'_atoms'=>$atoms];
        }
        $cash=Money::amount($input['cash_tendered']);if($cash<$total)throw new \InvalidArgumentException('Cash tendered does not cover this offline sale.');
        return ['lines'=>$lines,'subtotal'=>$total,'discount'=>0,'total'=>$total,'vat_rate'=>$lease->vat_rate,'vat_amount'=>Money::amount(ExactMath::roundProductRatio($total,$lease->vat_rate,100+$lease->vat_rate)),'cash_tendered'=>$cash,'change_given'=>$cash-$total];
    }

    private function commit(OfflineOperation $operation,OfflineLease $lease,User $actor,Device $device,Business $shop,TillSession $till,array $review,?User $reconciler): array {
        return DB::transaction(function()use($operation,$lease,$actor,$device,$shop,$till,$review,$reconciler){
            $products=Product::withTrashed()->whereIn('id',array_column($review['lines'],'_product_key'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $conflicts=$operation->conflicts??[];
            $lines=array_map(function($line){foreach(['_product_key','_packaging_key','_batch_keys','_atoms','proof','snapshot_id','stock','barcode']as$key)unset($line[$key]);return $line;},$review['lines']);
            $sequence=Money::amount(ExactMath::add($shop->sale_sequence,1));$shop->sale_sequence=$sequence;$shop->save();
            $originalTill=TillSession::whereKey($lease->till_session_id)->firstOrFail();
            $sale=Sale::create(['branch_id'=>$originalTill->branch_id,'cashier_id'=>$actor->id,'device_id'=>$device->id,'till_session_id'=>$till->id,
                'operation_id'=>$operation->operation_id,'request_fingerprint'=>$operation->request_fingerprint,
                'receipt_number'=>'RCP-'.now('Africa/Dar_es_Salaam')->year.'-'.str_pad((string)$sequence,6,'0',STR_PAD_LEFT),'status'=>'completed',
                'subtotal'=>$review['subtotal'],'discount'=>0,'total'=>$review['total'],'vat_amount'=>$review['vat_amount'],'vat_rate'=>$review['vat_rate'],
                'cash_tendered'=>$review['cash_tendered'],'change_given'=>$review['change_given'],'lines'=>$lines,'occurred_at'=>$operation->captured_at,'is_offline'=>true]);
            foreach($review['lines']as$index=>$line){
                $product=$products->get($line['_product_key']);if(!$product)throw new \LogicException('A leased product no longer exists.');
                if($product->trashed()||!$product->is_active)$conflicts[]='PRODUCT_INACTIVE';
                $pack=$line['_packaging_key']?ProductPackaging::whereKey($line['_packaging_key'])->first():null;
                if($line['_packaging_key']&&(!$pack||$pack->archived_at||!$pack->is_active||$pack->version!==$line['packaging_version']))$conflicts[]='PACKAGING_VERSION_CHANGED';
                $currentPrice=app(PricingService::class)->effectivePrice($product,$pack);
                $difference=abs($currentPrice-$line['unit_price']);
                if(($currentPrice===0&&$difference>0)||($currentPrice>0&&$difference>intdiv($currentPrice,20)))$conflicts[]='PRICE_CHANGED';
                $conflicts=[...$conflicts,...$this->stock->allocateOfflineSale($actor,$device,$product,$sale,$line['_atoms'],$index,$line['unit_price'],$line['_batch_keys'])];
            }
            SalePayment::create(['sale_id'=>$sale->id,'method'=>'cash','amount'=>$sale->total]);
            if($sale->total>0)$this->tills->recordCash($reconciler??$actor,$till,'cash_sale',$sale->total,$operation->operation_id);
            app(IncentiveService::class)->recordSale($actor,$sale);
            $operation->sale_id=$sale->id;$operation->conflicts=array_values(array_unique($conflicts));$operation->status=$operation->conflicts?'committed_with_conflict':'committed';
            $operation->result=['id'=>$operation->publicId(),'operation_id'=>$operation->operation_id,'status'=>$operation->status,'conflicts'=>$operation->conflicts,'sale'=>(new \App\Http\Resources\SaleResource($sale))->resolve(),'replayed'=>false];$operation->save();
            activity('offline')->performedOn($operation)->causedBy($reconciler??$actor)->event($reconciler?'offline_sale_reconciled':'offline_sale_committed')->withProperties(['operation_id'=>$operation->operation_id,'conflicts'=>$operation->conflicts,'sale_id'=>$sale->publicId()])->log('Offline sale recorded');
            return $operation->result;
        });
    }

    private function session(User $original,bool $owner=false): array {
        $original->assertCurrentBusiness();$actor=User::whereKey($original->id)->lockForUpdate()->first();$token=$original->currentAccessToken();
        $session=$token?PersonalAccessToken::whereKey($token->id)->where('tokenable_id',$original->id)->lockForUpdate()->first():null;
        $device=$session?Device::whereKey($session->device_id)->lockForUpdate()->first():null;
        if(!$actor||!$actor->is_active||$actor->must_change_pin||$actor->getRoleNames()->count()!==1||($actor->hasRole('owner')&&!$actor->ownerAuthenticationReady())||!$session||$session->expires_at?->isPast()||$device?->status!=='approved')throw new AuthenticationException;
        if(!$actor->can('sell')||($owner&&!$actor->hasRole('owner')))throw new AuthorizationException;
        return [$actor->withAccessToken($session),$device];
    }
    private function reconciliationTill(User $actor,string $id): TillSession {
        $till=(new TillSession)->resolveRouteBindingQuery(TillSession::with(['branch','cashier']),$id)->lockForUpdate()->first();
        if(!$till||$till->status!=='open'||!$till->branch->is_active||!$this->access->canManage($actor,$till->branch,$till->cashier))throw new ApiException('TILL_REQUIRED','Select your own open reconciliation till.',409);
        return $till;
    }
    private function reconciliationBody(array $input): array {$body=$input;unset($body['approval_token']);return $body;}
    private function publicResult(array $result): array {unset($result['reconciliation_fingerprint']);return $result;}
}
