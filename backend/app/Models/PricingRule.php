<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class PricingRule extends Model {
 use BelongsToBusiness,HasPublicId; public const PUBLIC_ID_PREFIX='price_rule_'; public const UPDATED_AT=null;
 protected $guarded=['id','business_id','public_id'];protected $hidden=['id','business_id','user_id'];
 protected function casts():array{return ['price'=>'integer','basis_points'=>'integer','buy_qty'=>'integer','free_qty'=>'integer','priority'=>'integer','max_atoms'=>'integer','starts_at'=>'datetime','ends_at'=>'datetime','cancelled_at'=>'datetime','reason'=>'encrypted'];}
 public function targets():HasMany{return $this->hasMany(PricingTarget::class);}
 protected static function booted():void{static::updating(function($m){foreach(array_keys($m->getDirty())as$key)if($key!=='cancelled_at')throw new \LogicException('Pricing rules are immutable; cancel and replace.');if($m->getRawOriginal('cancelled_at')!==null)throw new \LogicException('A cancelled rule is immutable.');});static::deleting(fn()=>throw new \LogicException('Pricing rules cannot be deleted.'));}
}
