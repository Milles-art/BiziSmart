<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class MobilePayment extends Model {
    use BelongsToBusiness,HasPublicId;
    public const PUBLIC_ID_PREFIX='mpay_';
    protected $guarded=['id','business_id','public_id'];
    protected $hidden=['id','business_id','actor_id','device_id','branch_id','till_session_id','request','request_fingerprint','merchant_fingerprint','sale_id','customer_payment_id'];
    protected function casts(): array {return ['request'=>'encrypted:array','amount'=>'integer','next_check_at'=>'datetime','last_checked_at'=>'datetime','confirmed_at'=>'datetime'];}
    protected static function booted(): void {
        static::updating(function($p){
            foreach(['public_id','business_id','actor_id','device_id','branch_id','till_session_id','operation_id','request_fingerprint','merchant_fingerprint','request','amount','currency','purpose'] as $c)
                if($p->isDirty($c)) throw new \LogicException('Payment request identity is immutable.');
            if(($p->getRawOriginal('sale_id') || $p->getRawOriginal('customer_payment_id') || $p->getRawOriginal('status')==='manual_refunded') && $p->isDirty()) throw new \LogicException('Allocated payments are immutable.');
            if($p->getRawOriginal('confirmed_at')) foreach(['provider_transaction_id','provider_reference','channel','confirmed_at'] as $c)
                if($p->isDirty($c)) throw new \LogicException('Confirmed payment evidence is immutable.');
        });
        static::deleting(fn()=>throw new \LogicException('Payments cannot be deleted.'));
    }
}
