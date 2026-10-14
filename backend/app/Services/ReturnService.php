<?php
namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{Business,Customer,DebtAllocation,Sale,SaleDebt,SaleItem,SaleReturn,SaleReturnItem,SupervisorApproval,TillSession,User};
use App\Support\{ExactMath,Money,Quantity};
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\{Str,Facades\DB};

class ReturnService {
    public function __construct(private TenantContext $context,private FinancialActorService $actors,private ReturnAccessService $access,
        private RefundCalculator $calculator,private StockService $stock,private DebtService $debts,private TillService $tills,private TillAccessService $tillAccess,private SaleService $sales) {}

    public function receipt(User $original,string $number): array {
        return DB::transaction(function() use($original,$number) {
            $actor=$this->actors->lock($original,'sell'); $this->shop();
            $sale=Sale::where('receipt_number',$number)->lockForUpdate()->firstOrFail(); $this->access->receipt($actor,$sale);
            $returned=$this->returned($sale); $lines=[];
            foreach($sale->lines as $index=>$line) {
                $sold=Quantity::parse($line['base_qty'],$line['quantity_type']==='weight'); $before=$returned[$index]??0;
                $lines[]=[...$line,'line_index'=>$index,'returned_qty'=>Quantity::format($before,$line['quantity_type']==='weight'),'remaining_qty'=>Quantity::format($sold-$before,$line['quantity_type']==='weight')];
            }
            return ['sale_id'=>$sale->publicId(),'receipt_number'=>$sale->receipt_number,'branch_id'=>$sale->branch->publicId(),'total'=>$sale->total,'lines'=>$lines,
                'has_mobile_payment'=>$this->hasMobilePayment($sale),'return_status'=>$this->status($sale,$returned),'created_at'=>$sale->created_at->toISOString()];
        },3);
    }
    public function preview(User $original,array $input): array {
        return DB::transaction(function() use($original,$input) {
            $actor=$this->actors->lock($original,'sell');$this->shop();$sale=$this->sale($actor,$input['sale_id']);
            return $this->review($actor,$sale,$input);
        },3);
    }
    public function complete(User $original,#[\SensitiveParameter] array $input): array {
        $original->assertCurrentBusiness();
        // Peek outside the transaction so it cannot establish a stale repeatable-read snapshot.
        $grant=SupervisorApproval::where('token_hash',hash('sha256',$input['approval_token']))->first();
        return DB::transaction(function() use($original,$input,$grant) {
            // Same ordered users as grant consumption, before the business/resource locks.
            User::whereIn('id',array_unique(array_filter([$original->id,$grant?->approver_id])))->orderBy('id')->lockForUpdate()->get();
            $actor=$this->actors->lock($original,'sell');$this->shop();$sale=$this->sale($actor,$input['sale_id']);
            $operation=strtolower($input['operation_id']);$fingerprint=SupervisorApprovalService::snapshotHash($input);
            $existing=SaleReturn::where('operation_id',$operation)->lockForUpdate()->first();
            if($existing) {
                if((int)$existing->user_id!==(int)$actor->id || (int)$existing->device_id!==(int)$actor->currentAccessToken()->device_id || !hash_equals($existing->request_fingerprint,$fingerprint))
                    throw new ApiException('OPERATION_ID_REUSED','This return reference was already used with different details.',409);
                return [...$this->result($existing),'replayed'=>true];
            }
            $review=$this->review($actor,$sale,$input);
            if(!hash_equals($review['review_hash'],$input['review_hash'])) throw new ApiException('RETURN_CHANGED','This return changed. Review it again and request fresh approval.',409);
            if($review['replacement'] && $review['additional_cash']<$review['cash_required']) throw new ApiException('PAYMENT_MISMATCH','Collect the cash difference before confirming this exchange.',400);
            return app(SupervisorApprovalService::class)->execute($actor,$input['approval_token'],'refund',$operation,$review['snapshot'],function(User $approver) use($actor,$sale,$input,$review,$operation,$fingerprint) {
                $replacementId=null;
                $till=(new TillSession)->resolveRouteBinding($input['till_id']);
                $return=SaleReturn::create(['sale_id'=>$sale->id,'user_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'approved_by'=>$approver->id,'till_session_id'=>$till->id,
                    'operation_id'=>$operation,'request_fingerprint'=>$fingerprint,'reason'=>trim($input['reason']),'refund_total'=>$review['refund_total'],'debt_reduction'=>$review['debt_reduction'],
                    'cash_refund'=>$review['cash_refund'],'mobile_refund'=>$review['mobile_refund']??0,'mobile_refund_reference'=>($review['mobile_refund']??0)>0?trim($input['mobile_refund_reference']):null,'mobile_refund_reference_hash'=>($review['mobile_refund']??0)>0?hash('sha256',strtolower(trim($input['mobile_refund_reference']))):null,'cash_collected'=>$review['replacement'] ? $review['cash_required'] : 0,'cash_paid'=>$review['cash_paid'],'lines'=>$review['lines']]);
                foreach($review['allocations'] as $allocation) {
                    $item=(new SaleItem)->resolveRouteBinding($allocation['sale_item_id']);
                    $batch=$this->stock->restoreReturn($actor,$return,$item,Quantity::parse($allocation['atoms']),$allocation['quarantined']);
                    SaleReturnItem::create(['sale_id'=>$sale->id,'sale_return_id'=>$return->id,'sale_item_id'=>$item->id,'product_id'=>$item->product_id,'batch_id'=>$batch->id,'qty_atoms'=>Quantity::parse($allocation['atoms'])]);
                }
                $this->debts->reduceForReturn($sale,$review['debt_reduction']);
                if($review['cash_refund']>0) $this->tills->recordCash($actor,$till,'cash_refund',$review['cash_refund'],$operation);
                if($review['replacement']) {
                    $body=$this->replacementInput($sale,$till,$input['replacement']);
                    $replacement=$this->sales->completeReplacement($actor,[...$body,'cash_tendered'=>Money::amount(ExactMath::add($review['cash_refund'],$review['additional_cash'])),
                        'review_hash'=>$review['replacement']['review_hash']],(string)Str::uuid());
                    $replacementId=$replacement['sale']->id;
                }
                if($replacementId!==null) {
                    \App\Models\SaleExchange::create(['sale_return_id'=>$return->id,'replacement_sale_id'=>$replacementId]);
                }
                app(IncentiveService::class)->recordReturn($return);
                activity('sales')->performedOn($return)->causedBy($actor)->event('sale_returned')->withProperties(['return_id'=>$return->publicId(),'sale_id'=>$sale->publicId(),'refund_total'=>$return->refund_total,'approved_by'=>$approver->publicId()])->log('Receipt-linked return completed');
                if(($input['operation_kind']??'return')==='void')activity('sales')->performedOn($return)->causedBy($actor)->event('sale_voided')->withProperties(['return_id'=>$return->publicId(),'sale_id'=>$sale->publicId(),'branch_id'=>$sale->branch->publicId(),'operation_id'=>$operation,'refund_total'=>$return->refund_total,'approved_by'=>$approver->publicId()])->log('Approved remaining receipt void completed');
                return [...$this->result($return),'replayed'=>false];
            });
        },3);
    }
    private function review(User $actor,Sale $sale,array $input): array {
        $kind=$input['operation_kind']??'return';
        if(!in_array($kind,['return','void'],true))throw new ApiException('VALIDATION_ERROR','Choose an ordinary return or a completed receipt void.',422);
        if($kind==='void'&&isset($input['replacement']))throw new ApiException('VOID_EXCHANGE_UNAVAILABLE','A completed receipt void cannot include a replacement sale.',422);
        $till=(new TillSession)->resolveRouteBindingQuery(TillSession::with(['branch','cashier']),$input['till_id'])->lockForUpdate()->firstOrFail();
        $till->branch->refreshForUpdate();
        if(!$this->tillAccess->canManage($actor,$till->branch,$till->cashier)||!$till->branch->is_active) throw new AuthorizationException;
        if($till->status!=='open') throw new ApiException('TILL_CLOSED','Open your own till to process a return.',409);
        if($sale->payments()->whereNotIn('method',['cash','credit','mpesa'])->exists()) throw new ApiException('REFUND_METHOD_UNAVAILABLE','This payment method is not connected for refunds.',400);
        $customer=$sale->customer_id ? Customer::whereKey($sale->customer_id)->lockForUpdate()->firstOrFail():null;
        $debt=SaleDebt::where('sale_id',$sale->id)->lockForUpdate()->first();$returned=$this->returned($sale);
        $lines=[];$allocations=[];$total=0;$seen=[];
        foreach($input['items'] as $entry) {
            $index=$entry['line_index'];$line=$sale->lines[$index]??null;
            if(!$line||isset($seen[$index])) throw new ApiException('VALIDATION_ERROR','Choose each original receipt line once.',422,'items');
            $seen[$index]=true;$weighted=$line['quantity_type']==='weight';
            try { $atoms=Quantity::parse($entry['qty'],$weighted);$sold=Quantity::parse($line['base_qty'],$weighted);$before=$returned[$index]??0;
                $amount=$this->calculator->line($line['line_total'],$sold,$before,$atoms);$total=Money::amount(ExactMath::add($total,$amount)); }
            catch(\InvalidArgumentException|\OverflowException $e) {throw new ApiException('RETURN_QUANTITY_INVALID',$e->getMessage(),422,'items');}
            if($kind==='void'&&$atoms!==$sold-$before)throw new ApiException('VOID_QUANTITY_REQUIRED','A receipt void must include every remaining quantity on every original line.',422,'items');
            $lines[]=['line_index'=>$index,'product_id'=>$line['product_id'],'name'=>$line['name'],'unit'=>$line['unit'],'quantity_type'=>$line['quantity_type'],
                'qty'=>Quantity::format($atoms,$weighted),'returned_before'=>Quantity::format($before,$weighted),'disposition'=>$entry['disposition'],'amount'=>$amount];
            $remaining=$atoms;
            foreach(SaleItem::with(['product','batch'])->where('sale_id',$sale->id)->where('line_index',$index)->orderBy('id')->lockForUpdate()->get() as $item) {
                if($remaining===0) break;
                $item->product->refreshForUpdate();$batch=$item->batch;$batch->refreshForUpdate();
                $used=Quantity::parse((string)SaleReturnItem::where('sale_item_id',$item->id)->lockForUpdate()->get()->sum('qty_atoms'));
                $take=min($remaining,$item->qty_atoms-$used);if($take<=0)continue;
                $quarantine=$entry['disposition']==='damaged'||$batch->is_quarantined||$batch->is_recalled||($batch->expiry_date && $batch->expiry_date->toDateString()<now('Africa/Dar_es_Salaam')->toDateString());
                $allocations[]=['sale_item_id'=>$item->publicId(),'batch_id'=>$batch->publicId(),'atoms'=>(string)$take,'quarantined'=>(bool)$quarantine,
                    'balance_before'=>(string)$batch->qty_remaining_atoms,'expiry_date'=>$batch->expiry_date?->toDateString(),'is_recalled'=>$batch->is_recalled,'is_quarantined'=>$batch->is_quarantined];
                $remaining-=$take;
            }
            if($remaining!==0) throw new ApiException('RETURN_QUANTITY_INVALID','The return exceeds the original batch allocations.',409);
        }
        if($kind==='void')foreach($sale->lines as $index=>$line){$sold=Quantity::parse($line['base_qty'],$line['quantity_type']==='weight');if($sold>($returned[$index]??0)&&!isset($seen[$index]))throw new ApiException('VOID_QUANTITY_REQUIRED','A receipt void must include every remaining quantity on every original line.',422,'items');}
        $collected=0;$mobileCollected=0;
        if($debt)foreach(DebtAllocation::where('sale_debt_id',$debt->id)->lockForUpdate()->get() as $allocation){$payment=\App\Models\CustomerPayment::whereKey($allocation->customer_payment_id)->lockForUpdate()->firstOrFail();if($payment->method==='mpesa')$mobileCollected=Money::amount(ExactMath::add($mobileCollected,$allocation->amount));else $collected=Money::amount(ExactMath::add($collected,$allocation->amount));}
        $paid=Money::amount(ExactMath::add((int)$sale->payments()->where('method','cash')->lockForUpdate()->get()->sum('amount'),$collected));
        $paid-=Money::amount((int)SaleReturn::where('sale_id',$sale->id)->lockForUpdate()->get()->sum('cash_refund'));
        $mobile=Money::amount(ExactMath::add($mobileCollected,(int)$sale->payments()->where('method','mpesa')->lockForUpdate()->get()->sum('amount')));
        $mobile-= (int)SaleReturn::where('sale_id',$sale->id)->lockForUpdate()->get()->sum('mobile_refund');
        try {$settlement=$mobile>0 ? $this->calculator->splitSettlement($total,$debt?->remaining_amount??0,$paid,$mobile) : $this->calculator->cashSettlement($total,$debt?->remaining_amount??0,$paid);}
        catch(\InvalidArgumentException|\OverflowException $e){throw new ApiException('REFUND_EXCEEDS_PAYMENT',$e->getMessage(),409);}
        if(($settlement['mobile_refund']??0)>0){
            if(isset($input['replacement']))throw new ApiException('MOBILE_EXCHANGE_UNAVAILABLE','Record the external mobile refund as a return, then review the replacement purchase separately.',409);
            $reference=trim($input['mobile_refund_reference']??'');
            if(($input['approval_token']??null) && ($reference===''||($input['mobile_refund_completed']??false)!==true))throw new ApiException('MOBILE_REFUND_REQUIRED','Record the external refund reference and confirm the mobile money has been returned.',422);
            if($reference!=='' && (SaleReturn::where('mobile_refund_reference_hash',hash('sha256',strtolower($reference)))->lockForUpdate()->exists()||\App\Models\ManualMobileRefund::where('external_reference_hash',hash('sha256',strtolower($reference)))->lockForUpdate()->exists()))throw new ApiException('REFUND_REFERENCE_REUSED','This external refund reference is already recorded.',409);
        }
        $replacement=isset($input['replacement']) ? $this->sales->previewReplacement($actor,$this->replacementInput($sale,$till,$input['replacement']),$settlement['debt_reduction']):null;
        $cashAllocation=$replacement ? $replacement['total']-($replacement['credit_amount']??0):0;
        $required=max(0,$cashAllocation-$settlement['cash_refund']);$cashPaid=max(0,$settlement['cash_refund']-$cashAllocation);
        $additional=isset($input['replacement']) ? $input['replacement']['cash_tendered']:0;
        $snapshot=['operation_id'=>strtolower($input['operation_id']),'sale_id'=>$sale->publicId(),'branch_id'=>$sale->branch->publicId(),'till_id'=>$till->publicId(),'till_revision'=>$till->revision,
            'reason'=>trim($input['reason']),'lines'=>$lines,'allocations'=>$allocations,'refund_total'=>$total,...$settlement,'unpaid_before'=>$debt?->remaining_amount??0,
            'customer_balance'=>$customer?->outstanding_balance,'replacement'=>$replacement,'additional_cash'=>$additional,'cash_required'=>$required,'cash_paid'=>$cashPaid];
        if(($settlement['mobile_refund']??0)>0)$snapshot=[...$snapshot,'mobile_refund_reference'=>trim($input['mobile_refund_reference']??''),'mobile_refund_completed'=>$input['mobile_refund_completed']??false,'external_transfer_executed'=>false];
        // Omitted ordinary-return input retains its historical review hash. Only void intent extends the bound snapshot.
        if($kind==='void')$snapshot['operation_kind']='void';
        return [...$snapshot,'snapshot'=>$snapshot,'review_hash'=>SupervisorApprovalService::snapshotHash($snapshot)];
    }
    private function replacementInput(Sale $sale,TillSession $till,array $replacement): array {
        $credit=$replacement['credit_amount']??0;
        if($credit>0&&!$sale->customer_id)throw new ApiException('CUSTOMER_REQUIRED','This receipt has no customer for replacement credit.',422);
        return ['branch_id'=>$till->branch->publicId(),'till_id'=>$till->publicId(),'items'=>$replacement['items'],
            ...($sale->customer_id?['customer_id'=>Customer::findOrFail($sale->customer_id)->publicId(),'credit_amount'=>$credit]:[])];
    }
    private function hasMobilePayment(Sale $sale):bool {
        if($sale->payments()->where('method','mpesa')->exists())return true;
        $debt=SaleDebt::where('sale_id',$sale->id)->first();if(!$debt)return false;
        $ids=DebtAllocation::where('sale_debt_id',$debt->id)->pluck('customer_payment_id');
        return \App\Models\CustomerPayment::whereIn('id',$ids)->where('method','mpesa')->exists();
    }
    private function returned(Sale $sale): array {
        $result=[];
        foreach(SaleItem::where('sale_id',$sale->id)->orderBy('id')->lockForUpdate()->get() as $item) {
            $atoms=Quantity::parse((string)SaleReturnItem::where('sale_item_id',$item->id)->lockForUpdate()->get()->sum('qty_atoms'));
            $result[$item->line_index]=ExactMath::add($result[$item->line_index]??0,$atoms);
        }
        return $result;
    }
    private function status(Sale $sale,array $returned): string {
        $any=false;$full=true;
        foreach($sale->lines as $i=>$line){$n=$returned[$i]??0;$any=$any||$n>0;$full=$full&&$n===Quantity::parse($line['base_qty'],$line['quantity_type']==='weight');}
        return $full?'full':($any?'partial':'none');
    }
    private function result(SaleReturn $return): array {
        $exchange=\App\Models\SaleExchange::where('sale_return_id',$return->id)->lockForUpdate()->first();
        return ['id'=>$return->publicId(),'sale_id'=>Sale::findOrFail($return->sale_id)->publicId(),'operation_id'=>$return->operation_id,
            'mobile_refund'=>$return->mobile_refund,'mobile_refund_reference'=>$return->mobile_refund_reference,'external_transfer_executed'=>false,'refund_total'=>$return->refund_total,'debt_reduction'=>$return->debt_reduction,'cash_refund'=>$return->cash_refund,'cash_collected'=>$return->cash_collected,'cash_paid'=>$return->cash_paid,
            'lines'=>$return->lines,'approved_by'=>User::withTrashed()->findOrFail($return->approved_by)->publicId(),'created_at'=>$return->created_at->toISOString(),
            'replacement'=>$exchange?(new \App\Http\Resources\SaleResource(Sale::findOrFail($exchange->replacement_sale_id)))->resolve():null];
    }
    private function sale(User $actor,string $public): Sale {$sale=(new Sale)->resolveRouteBindingQuery(Sale::query(),$public)->lockForUpdate()->firstOrFail();$this->access->receipt($actor,$sale);return $sale;}
    private function shop(): void {Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();}
}
