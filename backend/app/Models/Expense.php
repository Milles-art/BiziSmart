<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class Expense extends Model {use BelongsToBusiness,HasPublicId;public const PUBLIC_ID_PREFIX='exp_';protected $guarded=['id','business_id','public_id'];protected $hidden=['id','business_id','actor_id','device_id','expense_id','parent_payment_id','till_session_id','reference_hash','fingerprint','result','branch_id','cashier_id','receiver_id','acknowledged_by','reversed_by'];protected function casts():array{return ['amount'=>'integer','description'=>'encrypted','incurred_on'=>'date'];}protected static function booted():void{static::updating(fn()=>throw new \LogicException('Financial journals are append-only.'));static::deleting(fn()=>throw new \LogicException('Financial journals cannot be deleted.'));}}
