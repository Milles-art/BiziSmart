<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBarcode extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const PUBLIC_ID_PREFIX = 'bcd_';
    protected $fillable = ['product_id', 'barcode', 'packaging_id'];
    protected $hidden = ['id', 'public_id', 'business_id', 'product_id', 'packaging_id'];
    public function packaging(): BelongsTo { return $this->belongsTo(ProductPackaging::class); }
}
