<?php

namespace App\Models;

use App\Models\Concerns\{BelongsToBusiness, HasPublicId};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceHistory extends Model
{
    use BelongsToBusiness, HasPublicId;
    public const PUBLIC_ID_PREFIX = 'price_';
    public const UPDATED_AT = null;
    protected $guarded = ['id', 'business_id', 'public_id'];
    protected $hidden = ['id', 'business_id', 'product_id', 'packaging_id', 'user_id'];
    protected function casts(): array { return ['old_price' => 'integer', 'new_price' => 'integer', 'packaging_version' => 'integer']; }
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Price history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Price history is append-only.'));
    }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function packaging(): BelongsTo { return $this->belongsTo(ProductPackaging::class); }
}
