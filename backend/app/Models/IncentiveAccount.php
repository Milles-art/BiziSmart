<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class IncentiveAccount extends Model {use BelongsToBusiness,HasPublicId;public const PUBLIC_ID_PREFIX='iacct_';public const UPDATED_AT=null;protected $guarded=['id','business_id','public_id'];protected $hidden=['id','business_id','sale_id','cashier_id','customer_id','configuration_id'];protected function casts():array{return ['loyalty_tsh_per_point'=>'integer','commission_basis_points'=>'integer'];}protected static function booted():void{static::updating(fn()=>throw new \LogicException('Sale incentive terms are immutable.'));static::deleting(fn()=>throw new \LogicException('Sale incentive terms are immutable.'));}}
