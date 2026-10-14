<?php
namespace App\Models;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
class PricingOperation extends Model {use BelongsToBusiness;public const UPDATED_AT=null;protected $guarded=['id','business_id'];protected $hidden=['id','business_id','user_id','device_id','pricing_rule_id','request_fingerprint'];protected static function booted():void{static::updating(fn()=>throw new \LogicException('Pricing operations are append-only.'));static::deleting(fn()=>throw new \LogicException('Pricing operations are append-only.'));}}
