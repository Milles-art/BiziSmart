<?php
namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Resources\SaleResource;
use App\Models\{Business,MobilePayment,MobilePaymentNotification,Sale,User};
use App\Payments\{MobileMoneyGateway,SelcomMerchant};
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class MobilePaymentService {
    public function __construct(private TenantContext $context,private FinancialActorService $actors,
        private SaleService $sales,private MobileMoneyGateway $gateway,private SelcomMerchant $merchant) {}

    public function begin(User $original,array $input,string $key): array {
        $grant=isset($input['sale']['approval_token'])?\App\Models\SupervisorApproval::where('token_hash',hash('sha256',$input['sale']['approval_token']))->first():null;
        [$payment,$new]=DB::transaction(function()use($original,$input,$key,$grant){
            User::whereIn('id',array_unique(array_filter([$original->id,$grant?->approver_id])))->orderBy('id')->lockForUpdate()->get();
            $actor=$this->actors->lock($original,'sell'); $business=$this->shop();
            $fingerprint=SupervisorApprovalService::snapshotHash($input);$key=strtolower($key);
            $existing=MobilePayment::where('operation_id',$key)->lockForUpdate()->first();
            if($existing){$this->access($actor,$existing);if(!hash_equals($existing->request_fingerprint,$fingerprint))throw new ApiException('OPERATION_ID_REUSED','This payment reference has different details.',409);return [$existing,false];}
            $unresolved=MobilePayment::where('actor_id',$actor->id)->where('device_id',$actor->currentAccessToken()->device_id)->whereNull('sale_id')->whereNull('customer_payment_id')->whereNotIn('status',['failed','manual_refunded'])->lockForUpdate()->first();
            if($unresolved)throw new ApiException('PAYMENT_PENDING','Recover the existing mobile payment before requesting another.',409,details:['payment_id'=>$unresolved->publicId()]);
            $settings=$this->merchant->configuration($business);
            $purpose=isset($input['collection'])?'debt':'checkout';
            if($purpose==='checkout'){
            $sale=$input['sale'];$review=$this->sales->preview($actor,$sale);
            if(!hash_equals($review['review_hash'],$sale['review_hash']))throw new ApiException('SALE_CHANGED','Review current prices before requesting payment.',409);
            if($this->sales->hasDiscount($sale)){
                if(!isset($sale['approval_token']))throw new ApiException('APPROVAL_REQUIRED','Approve the discount before requesting payment.',403);
                $snapshot=$review;unset($snapshot['review_hash']);
                app(SupervisorApprovalService::class)->execute($actor,$sale['approval_token'],'give_discount',$key,$snapshot,fn()=>null,false);
            }
            if(!$this->sales->hasDiscount($sale)&&isset($sale['approval_token']))throw new ApiException('VALIDATION_ERROR','This sale has no discount to approve.',422);
            $needed=[];
            foreach($review['lines'] as $line){$p=(new \App\Models\Product)->resolveRouteBinding($line['product_id']);
                $atoms=\App\Support\Quantity::parse($line['base_qty'],$line['quantity_type']==='weight');
                $needed[$p->id]=\App\Support\ExactMath::add($needed[$p->id]??0,$atoms);}
            ksort($needed);
            foreach($needed as $productId=>$atoms){$available=0;
                $batches=app(StockService::class)->eligibleBatches(\App\Models\ProductBatch::where('product_id',$productId))->lockForUpdate()->get();
                foreach($batches as $batch)$available=\App\Support\ExactMath::add($available,$batch->qty_remaining_atoms);
                if($atoms>$available)throw new ApiException('INSUFFICIENT_STOCK','Not enough sellable stock to request this payment.',400);}
            $amount=Money::amount($sale['mobile_amount']);
            if($amount<=0 || $sale['cash_tendered']<$review['cash_allocated'])throw new ApiException('PAYMENT_MISMATCH','The selected amounts do not cover this sale.',422);
            $till=(new \App\Models\TillSession)->resolveRouteBinding($sale['till_id']);
            }else{
                $collection=$input['collection'];$customer=(new \App\Models\Customer)->resolveRouteBinding($collection['customer_id']);
                $review=app(DebtService::class)->preview($actor,$customer,$collection);
                if(!hash_equals($review['review_hash'],$collection['review_hash']))throw new ApiException('DEBT_CHANGED','Review current invoice balances before requesting payment.',409);
                $amount=$review['amount'];$till=(new \App\Models\TillSession)->resolveRouteBinding($collection['till_id']);
            }
            // This row is the durable dispatch claim: replay/recovery never repeats the create or push request.
            $payment=MobilePayment::create(['actor_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,
                'branch_id'=>$till->branch_id,'till_session_id'=>$till->id,'operation_id'=>$key,'request_fingerprint'=>$fingerprint,
                'merchant_fingerprint'=>$this->merchantHash($settings),'request'=>$input,'amount'=>$amount,'currency'=>'TZS',
                'purpose'=>$purpose,'status'=>'dispatching','next_check_at'=>now()->addMinutes(3)]);
            return [$payment,true];
        },3);
        if($new){
            $business=Business::findOrFail($this->context->requireId());$status='unknown';$error=null;
            try{
                $r=$payment->request;
                $created=$this->gateway->createOrder($business,$payment->publicId(),$payment->amount,$r['buyer_name'],$r['buyer_email'],$r['phone'],isset($r['sale'])?count($r['sale']['items']):1);
                if($created['accepted']){$push=$this->gateway->push($business,$payment->publicId(),'push_'.$payment->public_id,$r['phone']);$status=$push['accepted']?'awaiting':'unknown';}
            }catch(ApiException $e){$error=$e->errorCode;}
            DB::transaction(function()use($payment,$status,$error){$this->shop();$p=MobilePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if($p->confirmed_at===null){$p->status=$status;$p->last_error=$error;$p->save();}},3);
        }
        return $this->resource($payment->fresh());
    }

    public function status(User $original,MobilePayment $payment): array {
        DB::transaction(function()use($original,$payment){$actor=$this->actors->lock($original,'sell');$this->shop();$this->access($actor,$payment->refreshForUpdate());},3);
        $this->reconcile($payment->id);
        return $this->resource($payment->fresh());
    }

    public function checkoutReview(User $original,MobilePayment $payment):array {
        DB::transaction(function()use($original,$payment){$actor=$this->actors->lock($original,'sell');$this->shop();$this->access($actor,$payment->refreshForUpdate());},3);
        if($payment->purpose!=='checkout'||$payment->sale_id||$payment->status!=='confirmed')throw new ApiException('PAYMENT_NOT_CONFIRMED','A confirmed unused checkout is required.',409);
        $input=$payment->request['sale'];$review=$this->sales->preview($original,$input);
        if(!hash_equals($input['review_hash'],$review['review_hash']))throw new ApiException('SALE_CHANGED','The recorded checkout changed; owner review is required.',409);
        return ['operation_id'=>$payment->operation_id,'review'=>$review];
    }

    public function mine(User $original): array {
        return DB::transaction(function()use($original){$actor=$this->actors->lock($original,'sell');$this->shop();
            return ['payments'=>MobilePayment::where('actor_id',$actor->id)->where('device_id',$actor->currentAccessToken()->device_id)
                ->where('branch_id',$actor->branch_id)->whereNull('sale_id')->whereNull('customer_payment_id')->whereNotIn('status',['failed','manual_refunded'])->orderByDesc('id')->limit(20)->get()->map(fn($p)=>$this->resource($p))->all()];},3);
    }

    public function ownerList(User $actor): array {
        $actor->assertCurrentBusiness();if(!$actor->hasRole('owner'))throw new AuthorizationException;
        return ['payments'=>MobilePayment::whereNull('sale_id')->whereNull('customer_payment_id')->whereNotIn('status',['failed','manual_refunded'])->orderByDesc('id')->limit(50)->get()->map(fn($p)=>$this->resource($p))->all()];
    }

    public function complete(User $original,MobilePayment $payment,?string $approvalToken=null): array {
        // SaleService refreshes authority and consumes the payment inside the same stock/debt/till transaction.
        $payment->assertCurrentBusiness();
        DB::transaction(function()use($original,$payment){$actor=$this->actors->lock($original,'sell');$this->shop();$this->access($actor,$payment->refreshForUpdate());},3);
        if($payment->sale_id)return [...(new SaleResource(Sale::findOrFail($payment->sale_id)))->resolve(),'replayed'=>true];
        if($payment->purpose==='debt'){
            $input=[...$payment->request['collection'],'payment_id'=>$payment->publicId()];$customer=(new \App\Models\Customer)->resolveRouteBinding($input['customer_id']);unset($input['customer_id']);
            $result=app(DebtService::class)->collect($original,$customer,$input,$payment->operation_id);return $this->collectionResource($result['payment'],$result['replayed']);
        }
        $input=[...$payment->request['sale'],'payment_id'=>$payment->publicId()];
        if($approvalToken!==null)$input['approval_token']=$approvalToken;
        $result=$this->sales->complete($original,$input,$payment->operation_id);
        return [...(new SaleResource($result['sale']))->resolve(),'replayed'=>$result['replayed']];
    }

    public function notification(MobilePayment $payment,string $digest): void {
        $payment->assertCurrentBusiness();
        DB::transaction(function()use($payment,$digest){$this->shop();$p=MobilePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if(($p->confirmed_at!==null))return;
            $hash=hash('sha256',$digest);
            if(MobilePaymentNotification::where('mobile_payment_id',$p->id)->where('digest_hash',$hash)->lockForUpdate()->exists())return;
            MobilePaymentNotification::create(['mobile_payment_id'=>$p->id,'digest_hash'=>$hash]);
            $p->next_check_at=now();$p->save();
        },3);
    }

    /** Trusted scheduler/webhook trigger; tenant must already be set from a persisted mapping. */
    public function reconcile(int $id): void {
        try{$claim=DB::transaction(function()use($id){$business=$this->shop();$p=MobilePayment::whereKey($id)->lockForUpdate()->firstOrFail();
            if(($p->confirmed_at!==null) || ($p->next_check_at && $p->next_check_at->isFuture()))return null;
            $settings=$this->merchant->configuration($business);
            if(!hash_equals($p->merchant_fingerprint,$this->merchantHash($settings)))throw new ApiException('PAYMENT_MERCHANT_CHANGED','The original merchant configuration is required to reconcile this payment.',409);
            $p->next_check_at=now()->addMinutes(3);$p->save();return [$business,$p];},3);}catch(ApiException $e){
            DB::transaction(function()use($id,$e){$this->shop();$p=MobilePayment::whereKey($id)->lockForUpdate()->firstOrFail();if($p->confirmed_at===null){$p->status='unknown';$p->last_error=$e->errorCode;$p->next_check_at=now()->addMinutes(3);$p->save();}},3);throw $e;
        }
        if(!$claim)return;[$business,$payment]=$claim;
        try{$result=$this->gateway->status($business,$payment->publicId(),$payment->amount);$error=null;}
        catch(ApiException $e){$result=['state'=>'unknown'];$error=$e->errorCode;}
        try{DB::transaction(function()use($payment,$result,$error){$this->shop();$p=MobilePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if(($p->confirmed_at!==null))return;
            $p->last_checked_at=now();$p->last_error=$error;
            if($result['state']==='confirmed'){$p->status='confirmed';$p->confirmed_at=now();$p->provider_transaction_id=$result['transaction_id'];$p->provider_reference=$result['reference'];$p->channel=$result['channel'];}
            elseif($result['state']==='failed')$p->status='failed';
            elseif($result['state']==='pending')$p->status='awaiting';
            else $p->status='unknown';
            $p->save();},3);
        }catch(QueryException $e){if(!in_array((string)$e->getCode(),['23000'],true))throw $e;
            DB::transaction(function()use($payment){$this->shop();$p=MobilePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();if($p->confirmed_at===null){$p->status='unknown';$p->last_error='PAYMENT_REFERENCE_REUSED';$p->save();}},3);
            throw new ApiException('PAYMENT_REFERENCE_REUSED','Provider confirmation is already recorded against another payment. Owner review is required.',409);}
    }

    /** Called only by SaleService after actor/business locks, before creating any sale. */
    public function consumeReview(User $actor,array $input,string $key): MobilePayment {
        if(DB::transactionLevel()<1)throw new \LogicException('Payment allocation requires a sale transaction.');
        $p=(new MobilePayment)->resolveRouteBindingQuery(MobilePayment::query(),$input['payment_id']??'')->lockForUpdate()->firstOrFail();$this->access($actor,$p);
        if($p->purpose!=='checkout'||$p->status!=='confirmed'||$p->sale_id||$p->customer_payment_id)throw new ApiException('PAYMENT_NOT_CONFIRMED','This payment is not available for this sale.',409);
        if(strtolower($key)!==$p->operation_id)throw new ApiException('PAYMENT_MISMATCH','Use the original payment operation reference.',409);
        $expected=$p->request['sale'];$actual=$input;unset($actual['payment_id'],$actual['approval_token'],$expected['approval_token']);
        if(!hash_equals(SupervisorApprovalService::snapshotHash($expected),SupervisorApprovalService::snapshotHash($actual)) || $p->amount!==($input['mobile_amount']??0))
            throw new ApiException('PAYMENT_MISMATCH','This payment belongs to a different checkout review.',409);
        return $p;
    }

    public function consumeCollection(User $actor,\App\Models\Customer $customer,array $input,string $key):MobilePayment {
        if(DB::transactionLevel()<1)throw new \LogicException('Payment allocation requires a debt transaction.');
        $p=(new MobilePayment)->resolveRouteBindingQuery(MobilePayment::query(),$input['payment_id']??'')->lockForUpdate()->firstOrFail();$this->access($actor,$p);
        if($p->purpose!=='debt'||$p->status!=='confirmed'||$p->sale_id||$p->customer_payment_id)throw new ApiException('PAYMENT_NOT_CONFIRMED','This payment is not available for debt collection.',409);
        $actual=['customer_id'=>$customer->publicId(),...$input];unset($actual['payment_id']);
        if(strtolower($key)!==$p->operation_id || !hash_equals(SupervisorApprovalService::snapshotHash($p->request['collection']),SupervisorApprovalService::snapshotHash($actual)) || $p->amount!==$input['amount'])throw new ApiException('PAYMENT_MISMATCH','This payment belongs to another invoice review.',409);
        return $p;
    }
    private function collectionResource(\App\Models\CustomerPayment $p,bool $replayed=false):array{return ['id'=>$p->publicId(),'customer_id'=>\App\Models\Customer::findOrFail($p->customer_id)->publicId(),'amount'=>$p->amount,'method'=>$p->method,'allocations'=>$p->allocations,'created_at'=>$p->created_at->toISOString(),'replayed'=>$replayed];}
    public function resource(MobilePayment $p): array {
        return ['id'=>$p->publicId(),'operation_id'=>$p->operation_id,'discount_required'=>$p->purpose==='checkout'&&$this->sales->hasDiscount($p->request['sale']),'purpose'=>$p->purpose,'collection'=>$p->customer_payment_id?$this->collectionResource(\App\Models\CustomerPayment::findOrFail($p->customer_payment_id)):null,'status'=>$p->status,'amount'=>$p->amount,'currency'=>$p->currency,
            'phone_suffix'=>substr($p->request['phone'],-4),'created_at'=>$p->created_at->toISOString(),
            'confirmed_at'=>$p->confirmed_at?->toISOString(),'reference'=>$p->provider_reference,'last_error'=>$p->last_error,
            'owner_review_required'=>in_array($p->last_error,['PAYMENT_REFERENCE_REUSED','PAYMENT_MERCHANT_CHANGED','PAYMENT_UNCONFIGURED'],true)||($p->status==='confirmed'&&!$p->sale_id&&!$p->customer_payment_id&&$p->confirmed_at?->lte(now()->subHour())),
            'sale'=>$p->sale_id ? (new SaleResource(Sale::findOrFail($p->sale_id)))->resolve() : null];
    }
    private function access(User $actor,MobilePayment $p): void {
        $p->assertCurrentBusiness();
        if((int)$p->actor_id!==(int)$actor->id || (int)$p->device_id!==(int)$actor->currentAccessToken()->device_id || (int)$p->branch_id!==(int)$actor->branch_id)throw new AuthorizationException;
    }
    private function merchantHash(array $s): string{return hash('sha256',$s['vendor'].'|'.$s['api_key'].'|'.$s['base_url']);}
    private function shop(): Business{return Business::whereKey($this->context->requireId())->where('is_active',true)->lockForUpdate()->firstOrFail();}
}
