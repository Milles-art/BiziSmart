<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class AttendanceSession extends Model {
    use BelongsToBusiness,HasPublicId;
    public const PUBLIC_ID_PREFIX='att_';public $timestamps=false;
    protected $guarded=['id','business_id','public_id'];
    protected function casts():array{return ['started_at'=>'immutable_datetime','ended_at'=>'immutable_datetime','revision'=>'integer','created_at'=>'immutable_datetime'];}
    protected static function booted():void {
        static::updating(function($row){foreach(array_keys($row->getDirty()) as $field)if(!in_array($field,['ended_at','active_user_id','revision'],true))throw new \LogicException('Original attendance stamps are immutable.');if($row->isDirty('ended_at')&&$row->getOriginal('ended_at')!==null)throw new \LogicException('Original clock-out cannot be overwritten.');});
        static::deleting(fn()=>throw new \LogicException('Attendance history cannot be deleted.'));
    }
}
