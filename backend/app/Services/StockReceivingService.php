<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{Business, Device, Product, ProductBatch, ProductPackaging, StockMovement, User};
use App\Services\Concerns\StockQueries;
use App\Support\{ExactMath, Money, Quantity};
use App\Tenancy\TenantContext;
use Illuminate\Auth\{Access\AuthorizationException, AuthenticationException};
use Illuminate\Support\Facades\DB;

/**
 * Write path: receiving supplier deliveries into locked batch rows.
 * Callers: StockReceivingController, CatalogueImportService, SupplierService.
 */
class StockReceivingService
{
    use StockQueries;

    public function __construct(private TenantContext $context) {}

    public function receive(User $actor, Product $product, array $input): array
    {
        $product->assertCurrentBusiness();
        return DB::transaction(function () use ($actor, $product, $input) {
            $actor->refreshForUpdate(); $actor->unsetRelation('roles')->unsetRelation('permissions');
            Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
            if (! $actor->is_active || ! $actor->can('receive_stock')) { throw new AuthorizationException; }
            // Internal import/supplier flows may not carry a bearer token; HTTP entry is
            // session-checked by RequireStaffSession, so only re-verify a token that exists.
            $token = $actor->currentAccessToken(); $deviceId = null;
            if ($token) {
                $current = $token->fresh();
                $device = $current?->device_id ? Device::find($current->device_id) : null;
                if (! $current || $current->expires_at?->isPast() || ! $device || $device->status !== 'approved') { throw new AuthenticationException; }
                $deviceId = $device->id;
            }
            $product->refreshForUpdate();
            if ($product->trashed()) { throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404); }
            $packId = $input['packaging_id'] ?? null;
            try {
                $entered = Quantity::parse($input['qty'], weighted: $packId === null && $product->quantity_type === 'weight');
                if ($entered <= 0) { throw new \InvalidArgumentException('Receive a positive quantity.'); }
                $cost = Money::amount($input['cost_price']);
            } catch (\InvalidArgumentException|\OverflowException $e) { throw new ApiException('VALIDATION_ERROR', $e->getMessage(), 422); }
            $qty = Quantity::format($entered, $packId === null && $product->quantity_type === 'weight');
            $operation = strtolower($input['operation_id']);
            $fingerprint = hash('sha256', json_encode([$product->publicId(), $input['batch_number'], $input['expiry_date'] ?? null, $qty, $cost, $packId], JSON_THROW_ON_ERROR));
            $existing = ProductBatch::where('operation_id', $operation)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) { throw new ApiException('OPERATION_ID_REUSED', 'This receipt reference was already used with different details.', 409); }
                $existing->setRelation('product', $product);
                return ['batch' => $existing, 'movement' => StockMovement::where('operation_id', $operation)->firstOrFail(), 'replayed' => true];
            }
            if (StockMovement::where('operation_id', $operation)->exists()) { throw new ApiException('OPERATION_ID_REUSED', 'This stock reference was already used for another operation.', 409); }
            if (! $product->is_active) { throw new ApiException('PRODUCT_INACTIVE', 'Activate this product before receiving stock.', 409); }
            $expiry = $input['expiry_date'] ?? null;
            if ($product->tracks_expiry && $expiry === null) { throw new ApiException('VALIDATION_ERROR', 'An expiry date is required for this product.', 422, 'expiry_date'); }
            if ($expiry !== null && $expiry < now('Africa/Dar_es_Salaam')->toDateString()) { throw new ApiException('EXPIRED_STOCK', 'This delivery has already expired. Return the goods to the supplier.', 400, 'expiry_date'); }
            $pack = null;
            if ($packId !== null) {
                $pack = (new ProductPackaging)->resolveRouteBindingQuery(ProductPackaging::where('product_id', $product->id), $packId)->lockForUpdate()->firstOrFail();
                if ($pack->archived_at || ! $pack->is_active) { throw new ApiException('PACKAGING_VERSION_CHANGED', 'This pack is no longer active. Select its current version.', 409); }
            }
            try { $atoms = $pack ? Quantity::packageAtoms($qty, $pack->conversion_atoms) : $entered; }
            catch (\InvalidArgumentException|\OverflowException $e) { throw new ApiException('VALIDATION_ERROR', $e->getMessage(), 422, 'qty'); }
            try {
                $held = $this->checkedAtoms(ProductBatch::where('product_id', $product->id)->sum('qty_remaining_atoms'));
                $eligible = $this->checkedAtoms($this->eligibleBatches(ProductBatch::where('product_id', $product->id))->sum('qty_remaining_atoms'));
                ExactMath::add($held, $atoms); ExactMath::add($eligible, $atoms);
            } catch (\OverflowException $e) { throw new ApiException('VALIDATION_ERROR', 'This receipt would exceed the supported product stock quantity.', 422, 'qty'); }
            $receipt = ['operation_id' => $operation, 'qty' => $qty, 'base_qty' => Quantity::format($atoms, $product->quantity_type === 'weight'),
                'packaging_id' => $pack?->publicId(), 'packaging_name' => $pack?->name, 'packaging_version' => $pack?->version,
                'conversion_atoms' => (string) ($pack?->conversion_atoms ?? ($product->quantity_type === 'weight' ? 1000 : 1))];
            $batch = ProductBatch::create(['product_id' => $product->id, 'batch_number' => $input['batch_number'], 'expiry_date' => $expiry,
                'qty_remaining_atoms' => 0, 'cost_price' => $cost, 'received_at' => now(), 'operation_id' => $operation, 'request_fingerprint' => $fingerprint, 'receipt' => $receipt]);
            $batch->refreshForUpdate();
            $batch->qty_remaining_atoms = ExactMath::add($batch->qty_remaining_atoms, $atoms); $batch->save();
            $movement = StockMovement::create(['product_id' => $product->id, 'batch_id' => $batch->id, 'type' => 'receive', 'qty_change_atoms' => $atoms,
                'qty_after_atoms' => $batch->qty_remaining_atoms, 'user_id' => $actor->id, 'device_id' => $deviceId, 'operation_id' => $operation, 'receipt' => $receipt]);
            activity('stock')->performedOn($batch)->causedBy($actor)->event('stock_received')->withProperties(['attributes' => $receipt])->log('Stock received');
            $batch->setRelation('product', $product);
            return ['batch' => $batch, 'movement' => $movement, 'replayed' => false];
        }, 3);
    }
}
