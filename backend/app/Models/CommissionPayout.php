<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class CommissionPayout extends Model {use BelongsToBusiness,HasPublicId;public const PUBLIC_ID_PREFIX='comm_pay_';public const UPDATED_AT=null;protected $guarded=['id','business_id','public_id'];protected $hidden=['id','business_id','user_id','device_id','cashier_id','request_fingerprint','external_reference_hash'];protected function casts():array{return ['amount'=>'integer','external_reference'=>'encrypted','reason'=>'encrypted','paid_on'=>'date'];}protected static function booted():void{static::updating(fn()=>throw new \LogicException('Commission payout records are append-only.'));static::deleting(fn()=>throw new \LogicException('Commission payout records are append-only.'));}}
