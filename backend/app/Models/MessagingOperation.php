<?php
namespace App\Models;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
class MessagingOperation extends Model {use BelongsToBusiness;public $timestamps=false;protected $guarded=['id','business_id'];protected function casts():array{return ['result'=>'encrypted:array'];}protected static function booted():void {static::updating(fn()=>throw new \LogicException('Messaging operation history is immutable.'));static::deleting(fn()=>throw new \LogicException('Messaging operation history cannot be deleted.'));}}
