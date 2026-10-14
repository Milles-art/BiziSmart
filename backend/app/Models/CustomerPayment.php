<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness, HasPublicId};
use Illuminate\Database\Eloquent\Model;
class CustomerPayment extends Model
{
    use BelongsToBusiness, HasPublicId;
    public const PUBLIC_ID_PREFIX = 'cpay_';
    public const UPDATED_AT = null;
    protected $guarded = ['id','business_id','public_id'];
    protected $hidden = ['request_fingerprint','id','business_id','received_by','device_id','customer_id','till_session_id','mobile_payment_id'];
    protected function casts(): array { return ['amount'=>'integer','allocations'=>'array']; }
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Debt payments are append-only.'));
        static::deleting(fn () => throw new \LogicException('Debt payments are append-only.'));
    }
}
