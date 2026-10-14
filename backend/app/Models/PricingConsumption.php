<?php
namespace App\Models;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
class PricingConsumption extends Model {use BelongsToBusiness;public const UPDATED_AT=null;protected $guarded=['id','business_id'];protected $hidden=['id','business_id','pricing_rule_id','sale_id'];protected function casts():array{return ['qty_atoms'=>'integer'];}protected static function booted():void{static::updating(fn()=>throw new \LogicException('Pricing use is append-only.'));static::deleting(fn()=>throw new \LogicException('Pricing use is append-only.'));}}
