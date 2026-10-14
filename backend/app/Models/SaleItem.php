<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SaleItem extends Model {
    use BelongsToBusiness, HasPublicId;
    public const PUBLIC_ID_PREFIX = 'si_';
    public const UPDATED_AT = null;
    protected $guarded = ['id','business_id','public_id'];
    protected $hidden = ['id','business_id','product_id','batch_id','sale_id','qty_atoms'];
    protected function casts(): array { return ['qty_atoms'=>'integer','line_index'=>'integer','unit_price'=>'integer','cost_price'=>'integer']; }
    public function product(): BelongsTo { return $this->belongsTo(Product::class)->withTrashed(); }
    public function batch(): BelongsTo { return $this->belongsTo(ProductBatch::class,'batch_id'); }
    protected static function booted(): void {
        static::updating(fn () => throw new \LogicException('Sale allocations are append-only.'));
        static::deleting(fn () => throw new \LogicException('Sale allocations are append-only.'));
    }
}
