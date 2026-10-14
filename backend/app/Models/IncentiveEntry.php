<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class IncentiveEntry extends Model {use BelongsToBusiness,HasPublicId;public const PUBLIC_ID_PREFIX='earn_';public const UPDATED_AT=null;protected $guarded=['id','business_id','public_id'];protected $hidden=['id','business_id','incentive_account_id','cashier_id','customer_id'];protected function casts():array{return ['paid_net_value'=>'integer','points_total'=>'integer','commission_total'=>'integer','points_delta'=>'integer','commission_delta'=>'integer'];}protected static function booted():void{static::updating(fn()=>throw new \LogicException('Incentive earnings are append-only.'));static::deleting(fn()=>throw new \LogicException('Incentive earnings are append-only.'));}}
