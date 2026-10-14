<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class LoyaltyRestoration extends Model {use BelongsToBusiness,HasPublicId;public const PUBLIC_ID_PREFIX='restore_';public const UPDATED_AT=null;protected $guarded=['id','business_id','public_id'];protected $hidden=['id','business_id','loyalty_redemption_id','sale_return_id','customer_id'];protected function casts():array{return ['points'=>'integer'];}protected static function booted():void{static::updating(fn()=>throw new \LogicException('Point restorations are immutable.'));static::deleting(fn()=>throw new \LogicException('Point restorations are immutable.'));}}
