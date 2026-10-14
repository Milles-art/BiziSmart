<?php
namespace App\Models;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
class TillOperation extends Model {
    use BelongsToBusiness;
    public const UPDATED_AT = null;
    protected $guarded = ['id','business_id'];
    protected $hidden = ['id','business_id','user_id','device_id','request_fingerprint','result'];
    protected function casts(): array { return ['result'=>'encrypted:array']; }
    protected static function booted(): void {
        static::updating(fn () => throw new \LogicException('Till operations are immutable.'));
        static::deleting(fn () => throw new \LogicException('Till operations are immutable.'));
    }
}
