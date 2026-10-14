<?php
namespace App\Services;
use App\Models\{Branch,Business,ManualMobileRefund,MobilePayment,SupervisorApproval,User};
use App\Tenancy\TenantContext;
use App\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
class ManualMobileRefundService {
 public function __construct(private FinancialActorService $actors,private TenantContext $context){}
 public function preview(User $original,MobilePayment $p,array $input):array {
  return DB::transaction(function()use($original,$p,$input){$this->actor($original);$this->shop();$p->refreshForUpdate();return $this->review($p,$input);},3);
 }
 public function complete(User $original,MobilePayment $p,array $input):array {
  $original->assertCurrentBusiness();$p->assertCurrentBusiness();
  $grant=SupervisorApproval::where('token_hash',hash('sha256',$input['approval_token']))->first();
  return DB::transaction(function()use($original,$p,$input,$grant){
   User::whereIn('id',array_unique(array_filter([$original->id,$grant?->approver_id])))->orderBy('id')->lockForUpdate()->get();
   $actor=$this->actor($original);$this->shop();$p->refreshForUpdate();
   $fingerprint=SupervisorApprovalService::snapshotHash($input);$operation=strtolower($input['operation_id']);
   $old=ManualMobileRefund::where('operation_id',$operation)->lockForUpdate()->first();
   if($old){if($old->mobile_payment_id!==$p->id || $old->user_id!==$actor->id || $old->device_id!==$actor->currentAccessToken()->device_id || !hash_equals($old->request_fingerprint,$fingerprint))throw new ApiException('OPERATION_ID_REUSED','This refund reference has different details.',409);return [...$this->result($old),'replayed'=>true];}
   $review=$this->review($p,$input);if(!hash_equals($review['review_hash'],$input['review_hash']))throw new ApiException('PAYMENT_CHANGED','Review this recorded refund again.',409);
   $referenceHash=hash('sha256',strtolower(trim($input['external_reference'])));
   if(ManualMobileRefund::where('external_reference_hash',$referenceHash)->lockForUpdate()->exists()||\App\Models\SaleReturn::where('mobile_refund_reference_hash',$referenceHash)->lockForUpdate()->exists())throw new ApiException('REFUND_REFERENCE_REUSED','This external refund reference is already recorded.',409);
   return app(SupervisorApprovalService::class)->execute($actor,$input['approval_token'],'refund',$operation,$review['snapshot'],function(User $approver)use($actor,$p,$input,$fingerprint,$operation,$referenceHash){
    if(!$approver->hasRole('owner'))throw new AuthorizationException;
    $refund=ManualMobileRefund::create(['mobile_payment_id'=>$p->id,'user_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'approved_by'=>$approver->id,'operation_id'=>$operation,'request_fingerprint'=>$fingerprint,'external_reference_hash'=>$referenceHash,'external_reference'=>trim($input['external_reference']),'reason'=>trim($input['reason']),'amount'=>$p->amount]);
    $p->status='manual_refunded';$p->save();
    activity('payments')->performedOn($refund)->causedBy($actor)->event('manual_mobile_refund_recorded')->withProperties(['refund_id'=>$refund->publicId(),'payment_id'=>$p->publicId(),'amount'=>$p->amount])->log('External mobile refund recorded');
    return [...$this->result($refund),'replayed'=>false];
   });
  },3);
 }
 private function actor(User $original):User{$actor=$this->actors->lock($original,'refund');if(!$actor->hasRole('owner'))throw new AuthorizationException;return $actor;}
 private function shop():void{Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();}
 private function review(MobilePayment $p,array $input):array {
  if($p->status!=='confirmed'||$p->sale_id||$p->customer_payment_id)throw new ApiException('PAYMENT_NOT_REFUNDABLE','Only confirmed unallocated payments can use this resolution.',409);
  $snapshot=['operation_id'=>strtolower($input['operation_id']),'payment_id'=>$p->publicId(),'branch_id'=>Branch::findOrFail($p->branch_id)->publicId(),'amount'=>$p->amount,'provider_reference'=>$p->provider_reference,'external_reference'=>trim($input['external_reference']),'reason'=>trim($input['reason']),'refund_completed'=>true];
  return ['snapshot'=>$snapshot,'amount'=>$p->amount,'review_hash'=>SupervisorApprovalService::snapshotHash($snapshot),'external_transfer_executed'=>false];
 }
 private function result(ManualMobileRefund $r):array{return ['id'=>$r->publicId(),'amount'=>$r->amount,'external_reference'=>$r->external_reference,'external_transfer_executed'=>false];}
}
