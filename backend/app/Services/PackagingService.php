<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPackaging;
use App\Models\User;
use App\Support\Quantity;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PackagingService
{
    public function __construct(private TenantContext $context) {}

    public function save(User $actor, Product $product, array $input, ?ProductPackaging $packaging = null): ProductPackaging
    {
        return DB::transaction(function () use ($actor, $product, $input, $packaging) {
            $this->lock($actor, $product, $packaging);
            $before = $packaging ? $this->snapshot($packaging) : null;
            $attributes = $packaging ? $this->snapshot($packaging) : ['name' => '', 'conversion_atoms' => 0, 'selling_price' => 0, 'barcode' => null, 'is_active' => true];
            foreach (['name', 'selling_price', 'barcode', 'is_active'] as $field) {
                if (array_key_exists($field, $input)) { $attributes[$field] = $input[$field]; }
            }
            if (isset($input['base_qty'])) { $attributes['conversion_atoms'] = Quantity::parse($input['base_qty'], weighted: $product->quantity_type === 'weight'); }
            if ($attributes === $before) { return $packaging->setRelation('product', $product); }
            if ($packaging) { $packaging->archived_at = now(); $packaging->is_active = false; $packaging->save(); }
            $next = ProductPackaging::create([...$attributes, 'product_id' => $product->id,
                'family_id' => $packaging?->family_id ?? (string) Str::ulid(), 'version' => ($packaging?->version ?? 0) + 1]);
            if ($next->barcode !== null) { $this->reserveBarcode($next); }
            activity('products')->performedOn($next)->causedBy($actor)->event($packaging ? 'packaging_version_created' : 'packaging_created')
                ->withProperties(['old' => $before, 'attributes' => [...$attributes, 'packaging_id' => $next->publicId(), 'version' => $next->version]])->log('Product packaging changed');
            return $next->setRelation('product', $product);
        }, 3);
    }

    public function archive(User $actor, Product $product, ProductPackaging $packaging): void
    {
        DB::transaction(function () use ($actor, $product, $packaging) {
            $this->lock($actor, $product, $packaging);
            $before = $this->snapshot($packaging);
            $packaging->archived_at = now(); $packaging->is_active = false; $packaging->save();
            activity('products')->performedOn($packaging)->causedBy($actor)->event('packaging_archived')
                ->withProperties(['old' => $before, 'attributes' => ['archived' => true]])->log('Product packaging archived');
        }, 3);
    }

    private function lock(User $actor, Product $product, ?ProductPackaging $packaging): void
    {
        $product->assertCurrentBusiness(); $packaging?->assertCurrentBusiness();
        $actor->refreshForUpdate(); $actor->unsetRelation('roles')->unsetRelation('permissions');
        Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
        if (! $actor->is_active || ! $actor->can('manage_products')) { throw new AuthorizationException; }
        $product->refreshForUpdate();
        if ($product->trashed()) { throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404); }
        if ($packaging) {
            $packaging->refreshForUpdate();
            if ($packaging->product_id !== $product->id) { throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404); }
            if ($packaging->archived_at !== null) { throw new ApiException('PACKAGING_VERSION_CHANGED', 'This pack version has been archived. Refresh the pack list before editing.', 409); }
        }
    }

    private function reserveBarcode(ProductPackaging $packaging): void
    {
        $reserved = ProductBarcode::with('packaging')->where('barcode', $packaging->barcode)->first();
        if ($reserved && ($reserved->product_id !== $packaging->product_id || $reserved->packaging?->family_id !== $packaging->family_id)) {
            throw new ApiException('DUPLICATE_BARCODE', 'This barcode is already reserved in this shop.', 409, 'barcode');
        }
        if ($reserved) { $reserved->packaging_id = $packaging->id; $reserved->save(); }
        else { ProductBarcode::create(['product_id' => $packaging->product_id, 'packaging_id' => $packaging->id, 'barcode' => $packaging->barcode]); }
    }

    private function snapshot(ProductPackaging $packaging): array
    {
        return ['name' => $packaging->name, 'conversion_atoms' => $packaging->conversion_atoms, 'selling_price' => $packaging->selling_price,
            'barcode' => $packaging->barcode, 'is_active' => $packaging->is_active];
    }
}
