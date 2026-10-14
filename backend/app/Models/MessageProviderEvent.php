<?php
namespace App\Models;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
class MessageProviderEvent extends Model {use BelongsToBusiness;public $timestamps=false;protected $guarded=['id','business_id'];protected function casts():array{return ['created_at'=>'immutable_datetime'];}protected static function booted():void{static::updating(fn()=>throw new \LogicException('Provider evidence is immutable.'));static::deleting(fn()=>throw new \LogicException('Provider evidence is retained.'));}}
