<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToBusiness, HasPublicId;
    public const PUBLIC_ID_PREFIX = 'mov_';
    public const UPDATED_AT = null;
    protected $guarded = ['id', 'business_id', 'public_id'];
    protected $hidden = ['id', 'business_id', 'product_id', 'batch_id', 'user_id', 'device_id', 'qty_change_atoms', 'qty_after_atoms', 'request_fingerprint', 'approved_by'];
    protected function casts(): array { return ['qty_change_atoms' => 'integer', 'qty_after_atoms' => 'integer', 'receipt' => 'array', 'reason' => 'encrypted']; }
    public function product(): BelongsTo { return $this->belongsTo(Product::class)->withTrashed(); }
    public function batch(): BelongsTo { return $this->belongsTo(ProductBatch::class, 'batch_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class)->withTrashed(); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by')->withTrashed(); }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Stock movements are append-only.'));
        static::deleting(fn () => throw new \LogicException('Stock movements are append-only.'));
    }
}
