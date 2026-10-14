<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class StaffNotification extends Model {
    use BelongsToBusiness,HasPublicId;
    public const PUBLIC_ID_PREFIX='notice_';public $timestamps=false;protected $guarded=['id','business_id','public_id'];
    protected function casts():array{return ['payload'=>'encrypted:array','read_at'=>'immutable_datetime','created_at'=>'immutable_datetime'];}
    protected static function booted():void {static::updating(function($row){foreach(array_keys($row->getDirty()) as $field)if($field!=='read_at')throw new \LogicException('Notification events are immutable.');});static::deleting(fn()=>throw new \LogicException('Notification history cannot be deleted.'));}
}
