<?php
namespace App\Models;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PricingTarget extends Model {
 use BelongsToBusiness;public $timestamps=false;protected $guarded=['id','business_id'];protected $hidden=['id','business_id','pricing_rule_id','product_id','packaging_id','batch_id'];
 public function product():BelongsTo{return $this->belongsTo(Product::class)->withTrashed();}public function packaging():BelongsTo{return $this->belongsTo(ProductPackaging::class);}public function batch():BelongsTo{return $this->belongsTo(ProductBatch::class);}
 protected function casts():array{return ['activated_at'=>'datetime','finished_at'=>'datetime'];}
 protected static function booted():void{static::updating(function($m){foreach(array_keys($m->getDirty())as$key)if(!in_array($key,['activated_at','finished_at'])||$m->getRawOriginal($key)!==null)throw new \LogicException('Price targets are immutable; lifecycle markers are one-use.');});static::deleting(fn()=>throw new \LogicException('Price targets are immutable.'));}
}
