<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBatch extends Model
{
    use BelongsToBusiness, HasPublicId;
    public const PUBLIC_ID_PREFIX = 'bat_';
    protected $guarded = ['id', 'business_id', 'public_id'];
    protected $hidden = ['id', 'business_id', 'product_id', 'request_fingerprint', 'qty_remaining_atoms', 'source_batch_id'];
    protected function casts(): array
    {
        return ['qty_remaining_atoms' => 'integer', 'cost_price' => 'integer', 'expiry_date' => 'date', 'received_at' => 'datetime',
            'is_quarantined' => 'boolean', 'is_recalled' => 'boolean', 'receipt' => 'array'];
    }
    protected static function booted():void{static::updating(function($batch){if($batch->isDirty(['cost_price','receipt','received_at','product_id']))throw new \LogicException('Recorded batch acquisition costs and receipt snapshots are immutable.');});}
    public function product(): BelongsTo { return $this->belongsTo(Product::class)->withTrashed(); }
}
