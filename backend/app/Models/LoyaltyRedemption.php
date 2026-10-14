<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class LoyaltyRedemption extends Model {use BelongsToBusiness,HasPublicId;public const PUBLIC_ID_PREFIX='redeem_';public const UPDATED_AT=null;protected $guarded=['id','business_id','public_id'];protected $hidden=['id','business_id','sale_id','customer_id','configuration_id'];protected function casts():array{return ['points'=>'integer','point_value'=>'integer','discount_amount'=>'integer'];}protected static function booted():void{static::updating(fn()=>throw new \LogicException('Redemptions are immutable.'));static::deleting(fn()=>throw new \LogicException('Redemptions are immutable.'));}}
