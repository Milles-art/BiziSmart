<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class IncentiveConfiguration extends Model {use BelongsToBusiness,HasPublicId;public const PUBLIC_ID_PREFIX='icfg_';public const UPDATED_AT=null;protected $guarded=['id','business_id','public_id'];protected $hidden=['id','business_id','user_id','device_id','request_fingerprint'];protected function casts():array{return ['loyalty_tsh_per_point'=>'integer','loyalty_point_value'=>'integer','commission_basis_points'=>'integer','reason'=>'encrypted'];}protected static function booted():void{static::updating(fn()=>throw new \LogicException('Incentive terms are append-only.'));static::deleting(fn()=>throw new \LogicException('Incentive terms are append-only.'));}}
