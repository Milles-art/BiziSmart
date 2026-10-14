<?php

namespace App\Services;

use App\Models\{Product, ProductPackaging, ProductPriceHistory, User};
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

class ProductPriceHistoryService
{
    /** Called only within the catalogue write transaction, after its authority and row locks. */
    public function record(User $actor, Product $product, ?int $oldPrice, ?ProductPackaging $packaging = null, ?\DateTimeInterface $effectiveAt = null, ?string $sourceId = null): void
    {
        if (DB::transactionLevel() < 1) { throw new \LogicException('Price history requires a catalogue transaction.'); }
        $actor->assertCurrentBusiness(); $product->assertCurrentBusiness(); $packaging?->assertCurrentBusiness();
        if ($packaging && $packaging->product_id !== $product->id) { throw new \LogicException('The packaging must belong to this product.'); }
        $price = $packaging?->selling_price ?? $product->selling_price;
        if ($oldPrice === $price) { return; }
        ProductPriceHistory::create([
            'product_id' => $product->id, 'packaging_id' => $packaging?->id, 'user_id' => $actor->id,
            'product_name' => $product->name, 'packaging_name' => $packaging?->name,
            'unit' => $product->unit, 'base_qty' => $packaging ? Quantity::format($packaging->conversion_atoms, $product->quantity_type === 'weight') : '1',
            'packaging_version' => $packaging?->version, 'old_price' => $oldPrice, 'new_price' => $price,
            ...($sourceId !== null ? ['source' => 'schedule', 'source_id' => $sourceId, 'created_at' => $effectiveAt ?? now()] : []),
        ]);
    }
}
