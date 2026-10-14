<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class ManualMobileRefund extends Model {
 use BelongsToBusiness,HasPublicId;
 public const PUBLIC_ID_PREFIX='mref_';public const UPDATED_AT=null;
 protected $guarded=['id','business_id','public_id'];
 protected $hidden=['id','business_id','mobile_payment_id','user_id','device_id','approved_by','external_reference_hash','request_fingerprint'];
 protected function casts(): array{return ['amount'=>'integer','reason'=>'encrypted','external_reference'=>'encrypted'];}
 protected static function booted():void{static::updating(fn()=>throw new \LogicException('Manual refunds are append-only.'));static::deleting(fn()=>throw new \LogicException('Manual refunds are append-only.'));}
}
