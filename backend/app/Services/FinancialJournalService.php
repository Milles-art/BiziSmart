<?php
namespace App\Services;
use App\Exceptions\ApiException;
use App\Models\{Business,FinancialOperation,User};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
class FinancialJournalService {
 public function lock(User $original,string $permission='manage_settings',?string $grant=null):User {
  if(DB::transactionLevel()<1)throw new \LogicException('A financial journal needs its outer transaction.');
  $approver=$grant?\App\Models\SupervisorApproval::where('token_hash',hash('sha256',$grant))->first():null;
  User::whereIn('id',array_unique(array_filter([$original->id,$approver?->approver_id])))->orderBy('id')->lockForUpdate()->get();
  $actor=app(FinancialActorService::class)->lock($original,$permission);
  Business::whereKey(app(TenantContext::class)->requireId())->where('is_active',true)->lockForUpdate()->firstOrFail();
  return $actor;
 }
 public function replay(User $actor,string $type,array $snapshot):?array {
  $old=FinancialOperation::where('operation_id',$snapshot['operation_id'])->lockForUpdate()->first();if(!$old)return null;
  if($old->actor_id!==$actor->id||$old->device_id!==$actor->currentAccessToken()->device_id||$old->type!==$type||!hash_equals($old->fingerprint,SupervisorApprovalService::snapshotHash($snapshot)))throw new ApiException('OPERATION_ID_REUSED','This financial reference was already used with different details.',409);
  return [...$old->result,'replayed'=>true];
 }
 public function remember(User $actor,string $type,array $snapshot,array $result):array {
  FinancialOperation::create(['actor_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'operation_id'=>$snapshot['operation_id'],'type'=>$type,'fingerprint'=>SupervisorApprovalService::snapshotHash($snapshot),'result'=>$result]);
  activity('finance')->causedBy($actor)->event($type)->withProperties(['operation_id'=>$snapshot['operation_id']])->log('Financial journal recorded');
  return [...$result,'replayed'=>false];
 }
}
