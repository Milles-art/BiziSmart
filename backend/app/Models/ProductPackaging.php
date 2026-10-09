<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPackaging extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const PUBLIC_ID_PREFIX = 'pkg_';
    protected $fillable = ['product_id', 'family_id', 'version', 'name', 'conversion_atoms', 'selling_price', 'barcode', 'is_active', 'archived_at'];
    protected $hidden = ['id', 'public_id', 'business_id', 'product_id', 'family_id', 'conversion_atoms'];
    protected function casts(): array
    {
        return ['version' => 'integer', 'conversion_atoms' => 'integer', 'selling_price' => 'integer', 'is_active' => 'boolean', 'archived_at' => 'datetime'];
    }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
