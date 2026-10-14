<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class FinancialOperation extends Model {use BelongsToBusiness,HasPublicId;public const PUBLIC_ID_PREFIX='finop_';protected $guarded=['id','business_id','public_id'];protected $hidden=['id','business_id','actor_id','device_id','expense_id','parent_payment_id','till_session_id','reference_hash','fingerprint','result','branch_id','cashier_id','receiver_id','acknowledged_by','reversed_by'];protected function casts():array{return ['actor_id'=>'integer','device_id'=>'integer','result'=>'encrypted:array'];}protected static function booted():void{static::updating(fn()=>throw new \LogicException('Financial journals are append-only.'));static::deleting(fn()=>throw new \LogicException('Financial journals cannot be deleted.'));}}
