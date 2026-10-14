<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class MessageDelivery extends Model {use BelongsToBusiness,HasPublicId;public const PUBLIC_ID_PREFIX='msg_';protected $guarded=['id','business_id','public_id'];protected function casts():array{return ['recipient'=>'encrypted','message'=>'encrypted','dispatch_started_at'=>'immutable_datetime','accepted_at'=>'immutable_datetime','delivered_at'=>'immutable_datetime'];}protected static function booted():void {static::updating(function($row){foreach(array_keys($row->getDirty())as $field)if(!in_array($field,['status','provider_id','failure_code','dispatch_started_at','accepted_at','delivered_at','updated_at'],true))throw new \LogicException('Message source snapshots are immutable.');});static::deleting(fn()=>throw new \LogicException('Delivery history cannot be deleted.'));}}
