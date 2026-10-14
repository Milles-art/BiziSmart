<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class SubscriptionPayment extends Model {
    use BelongsToBusiness,HasPublicId;
    public const PUBLIC_ID_PREFIX='spay_';public $timestamps=false;protected $guarded=['id','business_id','public_id'];
    protected function casts():array{return ['amount'=>'integer','reference'=>'encrypted','paid_at'=>'immutable_datetime'];}
    protected static function booted():void{static::updating(fn()=>throw new \LogicException('Subscription payments are immutable.'));static::deleting(fn()=>throw new \LogicException('Subscription payments cannot be deleted.'));}
}
