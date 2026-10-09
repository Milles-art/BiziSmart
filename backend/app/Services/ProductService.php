<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use App\Support\Quantity;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function __construct(private TenantContext $context) {}

    public function save(User $actor, array $input, ?Product $product = null): Product
    {
        $product?->assertCurrentBusiness();
        return DB::transaction(function () use ($actor, $input, $product) {
            $business = $this->lockShop($actor);
            if ($product) {
                $product->refreshForUpdate();
                if ($product->trashed()) { throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404); }
                if (isset($input['quantity_type']) && $input['quantity_type'] !== $product->quantity_type) {
                    throw new ApiException('VALIDATION_ERROR', 'Create a new product to change its quantity type.', 422, 'quantity_type');
                }
            } else {
                if ((bool) ($input['is_active'] ?? true)) { $this->checkLimit($business); }
                $product = new Product(['quantity_type' => $input['quantity_type'] ?? 'counted', 'reorder_level_atoms' => 0,
                    'tracks_expiry' => false, 'is_active' => true]);
            }
            if ($product->exists && ! $product->is_active && ($input['is_active'] ?? false)) { $this->checkLimit($business); }
            $before = $product->exists ? $this->snapshot($product) : null;
            foreach (['name', 'barcode', 'category', 'unit', 'buying_price', 'selling_price', 'tracks_expiry', 'is_active'] as $field) {
                if (array_key_exists($field, $input)) { $product->{$field} = $input[$field]; }
            }
            if ($product->quantity_type === 'weight' && $product->unit !== 'kg') {
                throw new ApiException('VALIDATION_ERROR', 'Weighed products use kg as their entry and pricing unit.', 422, 'unit');
            }
            if (isset($input['reorder_level'])) {
                $product->reorder_level_atoms = Quantity::parse($input['reorder_level'], weighted: $product->quantity_type === 'weight');
            }
            $product->save();
            if ($product->barcode !== null) { $this->reserveBarcode($product); }
            $after = $this->snapshot($product);
            if ($before !== $after) {
                activity('products')->performedOn($product)->causedBy($actor)->event($before === null ? 'product_created' : 'product_updated')
                    ->withProperties(['old' => $before, 'attributes' => $after])->log('Product catalogue changed');
            }
            return $product;
        }, 3);
    }

    public function archive(User $actor, Product $product): void
    {
        $product->assertCurrentBusiness();
        DB::transaction(function () use ($actor, $product) {
            $this->lockShop($actor);
            $product->refreshForUpdate();
            if ($product->trashed()) { throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404); }
            $before = $this->snapshot($product);
            $product->is_active = false;
            $product->save();
            $product->delete();
            activity('products')->performedOn($product)->causedBy($actor)->event('product_archived')
                ->withProperties(['old' => $before, 'attributes' => ['is_active' => false, 'archived' => true]])->log('Product archived');
        }, 3);
    }

    private function lockShop(User $actor): Business
    {
        // Same actor -> shop order as staff/security writes; serialises limits and barcode reservations per shop.
        $actor->refreshForUpdate();
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $business = Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
        if (! $actor->is_active || ! $actor->can('manage_products')) { throw new AuthorizationException; }
        return $business;
    }

    private function checkLimit(Business $business): void
    {
        $limit = $business->plan->limits['products'] ?? null;
        if ($limit !== null && Product::where('is_active', true)->count() >= $limit) {
            throw new ApiException('PLAN_LIMIT_REACHED', 'Your plan’s active product limit has been reached.', 403);
        }
    }

    private function reserveBarcode(Product $product): void
    {
        $reserved = ProductBarcode::where('barcode', $product->barcode)->first();
        if ($reserved && ($reserved->product_id !== $product->id || $reserved->packaging_id !== null)) {
            throw new ApiException('DUPLICATE_BARCODE', 'This barcode is already reserved in this shop.', 409, 'barcode');
        }
        if (! $reserved) { ProductBarcode::create(['product_id' => $product->id, 'barcode' => $product->barcode]); }
    }

    private function snapshot(Product $product): array
    {
        return ['name' => $product->name, 'barcode' => $product->barcode, 'category' => $product->category,
            'quantity_type' => $product->quantity_type, 'unit' => $product->unit, 'buying_price' => $product->buying_price,
            'selling_price' => $product->selling_price, 'reorder_level' => Quantity::format($product->reorder_level_atoms, $product->quantity_type === 'weight'),
            'tracks_expiry' => $product->tracks_expiry, 'is_active' => $product->is_active];
    }
}
