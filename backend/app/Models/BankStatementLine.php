<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class BankStatementLine extends Model {
 use BelongsToBusiness,HasPublicId;
 public const PUBLIC_ID_PREFIX='bank_'; public $timestamps=false;
 protected $guarded=['id','business_id','public_id'];
 protected function casts():array{return ['account'=>'encrypted','reference'=>'encrypted','amount'=>'integer','booked_on'=>'immutable_date'];}
 protected static function booted():void {static::updating(function($row){foreach(array_keys($row->getDirty()) as $field)if(!in_array($field,['matched_type','matched_id'],true))throw new \LogicException('Statement evidence is immutable.');});static::deleting(fn()=>throw new \LogicException('Statement evidence cannot be deleted.'));}
}
