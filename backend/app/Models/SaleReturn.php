<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class SaleReturn extends Model {
    use BelongsToBusiness,HasPublicId;
    public const PUBLIC_ID_PREFIX = 'ret_';
    public const UPDATED_AT = null;
    protected $guarded = ['id','business_id','public_id'];
    protected $hidden = ['id','business_id','sale_id','user_id','device_id','approved_by','till_session_id','request_fingerprint','mobile_refund_reference_hash'];
    protected function casts(): array { return ['mobile_refund'=>'integer','mobile_refund_reference'=>'encrypted','refund_total'=>'integer','debt_reduction'=>'integer','cash_refund'=>'integer','cash_collected'=>'integer','cash_paid'=>'integer','reason'=>'encrypted','lines'=>'array']; }
    protected static function booted(): void {
        static::updating(fn()=>throw new \LogicException('Return journals are append-only.'));
        static::deleting(fn()=>throw new \LogicException('Return journals are append-only.'));
    }
}
