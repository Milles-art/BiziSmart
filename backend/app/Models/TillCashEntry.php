<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness, HasPublicId};
use Illuminate\Database\Eloquent\Model;
class TillCashEntry extends Model {
    use BelongsToBusiness, HasPublicId;
    public const PUBLIC_ID_PREFIX = 'tce_'; public const UPDATED_AT = null;
    protected $guarded = ['id','business_id','public_id'];
    protected $hidden = ['id','business_id','till_session_id','user_id','request_fingerprint'];
    protected function casts(): array { return ['amount'=>'integer','reason'=>'encrypted']; }
    protected static function booted(): void {
        static::updating(fn () => throw new \LogicException('Cash entries are append-only.'));
        static::deleting(fn () => throw new \LogicException('Cash entries are append-only.'));
    }
}
