<?php
namespace App\Models;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
class MobilePaymentNotification extends Model {
    use BelongsToBusiness;
    public const UPDATED_AT=null;
    protected $guarded=['id','business_id'];
    protected $hidden=['id','business_id','mobile_payment_id','digest_hash'];
    protected static function booted(): void {
        static::updating(fn()=>throw new \LogicException('Payment notifications are append-only.'));
        static::deleting(fn()=>throw new \LogicException('Payment notifications are append-only.'));
    }
}
