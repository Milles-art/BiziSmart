<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class LossAlert extends Model {
    use BelongsToBusiness,HasPublicId;
    public const PUBLIC_ID_PREFIX='la_';
    protected $guarded=['id','business_id','public_id'];
    protected $hidden=['id','business_id','user_id','acknowledged_by'];
    protected function casts():array{return ['evidence'=>'array','acknowledged_at'=>'datetime'];}
    public function staff(){return $this->belongsTo(User::class,'user_id')->withTrashed();}
    protected static function booted():void{static::updating(function($alert){foreach(array_keys($alert->getDirty())as $key)if(!in_array($key,['acknowledged_at','acknowledged_by','updated_at'],true))throw new \LogicException('Alert evidence is immutable.');});static::deleting(fn()=>throw new \LogicException('Loss alerts must be retained.'));}
}
