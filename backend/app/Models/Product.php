<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToBusiness, HasPublicId, SoftDeletes;

    public const PUBLIC_ID_PREFIX = 'prod_';
    protected $fillable = ['name', 'barcode', 'category', 'quantity_type', 'unit', 'buying_price', 'selling_price',
        'reorder_level_atoms', 'tracks_expiry', 'is_active'];
    protected $hidden = ['id', 'public_id', 'business_id', 'buying_price', 'reorder_level_atoms'];

    protected function casts(): array
    {
        return ['buying_price' => 'integer', 'selling_price' => 'integer', 'reorder_level_atoms' => 'integer',
            'tracks_expiry' => 'boolean', 'is_active' => 'boolean'];
    }

    public function activePackagings(): HasMany { return $this->hasMany(ProductPackaging::class)->whereNull('archived_at')->where('is_active', true); }
    public function barcodes(): HasMany { return $this->hasMany(ProductBarcode::class); }
}
