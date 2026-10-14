<?php
namespace App\Services;
use App\Exceptions\ApiException;
use App\Models\{Business,Customer,LoyaltyCard,User};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
class LoyaltyCardService {
 public function __construct(private TenantContext $context,private FinancialActorService $actors){}
 public function current(Customer $customer):?LoyaltyCard{$customer->assertCurrentBusiness();return LoyaltyCard::where('customer_id',$customer->id)->where('active',true)->first();}
 public function issue(User $original,Customer $customer,string $operation):array {
  $customer->assertCurrentBusiness();return DB::transaction(function()use($original,$customer,$operation){$actor=$this->actors->lock($original,'manage_customers');Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();$customer->refreshForUpdate();$key=strtolower($operation);$fingerprint=hash('sha256',$customer->publicId());$existing=LoyaltyCard::where('operation_id',$key)->lockForUpdate()->first();if($existing){if($existing->user_id!==$actor->id||$existing->device_id!==$actor->currentAccessToken()->device_id||!hash_equals($existing->request_fingerprint,$fingerprint))throw new ApiException('OPERATION_ID_REUSED','This reference belongs to different card details or staff.',409);return ['card'=>$existing,'replayed'=>true];}
   LoyaltyCard::where('customer_id',$customer->id)->where('active',true)->update(['active'=>false]);$token='BSL:'.bin2hex(random_bytes(16));$card=LoyaltyCard::create(['customer_id'=>$customer->id,'user_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'operation_id'=>$key,'request_fingerprint'=>$fingerprint,'token'=>$token,'token_hash'=>hash('sha256',$token),'active'=>true]);activity('customers')->performedOn($card)->causedBy($actor)->event('loyalty_card_issued')->withProperties(['customer_id'=>$customer->publicId(),'card_id'=>$card->publicId()])->log('Loyalty card issued; previous card revoked');return ['card'=>$card,'replayed'=>false];},3);
 }
 public function resolve(string $token):Customer {$card=LoyaltyCard::where('token_hash',hash('sha256',$token))->where('active',true)->firstOrFail();return Customer::findOrFail($card->customer_id);}
}
