<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class AttendanceCorrection extends Model {
    use BelongsToBusiness,HasPublicId;
    public const PUBLIC_ID_PREFIX='acorr_';public $timestamps=false;protected $guarded=['id','business_id','public_id'];
    protected function casts():array{return ['original_snapshot'=>'encrypted:array','reason'=>'encrypted','corrected_started_at'=>'immutable_datetime','corrected_ended_at'=>'immutable_datetime','created_at'=>'immutable_datetime'];}
    protected static function booted():void {static::updating(fn()=>throw new \LogicException('Attendance corrections are append-only.'));static::deleting(fn()=>throw new \LogicException('Attendance corrections cannot be deleted.'));}
}
