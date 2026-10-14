<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo,HasMany};
class Sale extends Model {
    use BelongsToBusiness, HasPublicId;
    public const PUBLIC_ID_PREFIX = 'sale_';
    protected $guarded = ['id','business_id','public_id'];
    protected $hidden = ['id','business_id','cashier_id','device_id','branch_id','till_session_id','customer_id','request_fingerprint','discount_approved_by','discount_reason'];
    protected function casts(): array { return ['subtotal'=>'integer','discount'=>'integer','total'=>'integer','vat_amount'=>'integer','vat_rate'=>'integer','cash_tendered'=>'integer','change_given'=>'integer','occurred_at'=>'immutable_datetime','is_offline'=>'boolean','loyalty_redemption'=>'array','lines'=>'array','discount_reason'=>'encrypted']; }
    public function items(): HasMany { return $this->hasMany(SaleItem::class); }
    public function payments(): HasMany { return $this->hasMany(SalePayment::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function cashier(): BelongsTo { return $this->belongsTo(User::class,'cashier_id')->withTrashed(); }
    public function till(): BelongsTo { return $this->belongsTo(TillSession::class,'till_session_id'); }
    protected static function booted(): void {
        static::updating(fn () => throw new \LogicException('Completed sale snapshots are immutable.'));
        static::deleting(fn () => throw new \LogicException('Sales cannot be deleted.'));
    }
}
