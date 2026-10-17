<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{Business, Product, ProductBatch, StockMovement, User};
use App\Services\Concerns\{StockLossCounts, StockQueries};
use App\Support\{ExactMath, Quantity};
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Write path: preview + commit of stock corrections, losses and counts.
 * Callers: StockAdjustmentController, StockLossCountController (via StockService facade).
 * Stockkeeper commits route through SupervisorApprovalService; owners adjust directly.
 */
class StockAdjustmentService
{
    use StockQueries, StockLossCounts;

    public function __construct(private TenantContext $context, private FinancialActorService $actors) {}

    public function previewAdjustment(User $actor, array $input, array $classification = []): array
    {
        $actor->assertCurrentBusiness();
        if (! $actor->can('adjust_stock')) { throw new AuthorizationException; }
        $batch = $this->adjustmentBatch($input['batch_id']);
        [$delta, $change, $reason] = $this->correctionValues($batch->product, $input, ($classification['type'] ?? '') === 'stock_count');
        $after = $this->correctionBalance($batch->qty_remaining_atoms, $delta);
        $this->checkCorrectionAggregates($batch, $delta, $after);
        $snapshot = $this->correctionSnapshot($batch, $input['operation_id'], $change, Quantity::format($batch->qty_remaining_atoms, $batch->product->quantity_type === 'weight'), $reason);
        if ($classification) $snapshot['classification'] = $classification;
        return ['snapshot' => $snapshot, 'payload_hash' => SupervisorApprovalService::snapshotHash($snapshot),
            'qty_after' => Quantity::format($after, $batch->product->quantity_type === 'weight'), 'approval_required' => ! $actor->hasRole('owner')];
    }

    public function adjust(User $requester, #[\SensitiveParameter] array $input, array $classification = []): array
    {
        $requester->assertCurrentBusiness();
        return DB::transaction(function () use ($requester, $input, $classification) {
            // Match the approval service's ordered user locks before business/product/batch locks.
            $grant = isset($input['approval_token']) ? \App\Models\SupervisorApproval::where('token_hash', hash('sha256', $input['approval_token']))->first() : null;
            User::whereIn('id', array_unique(array_filter([$requester->id, $grant?->approver_id])))->orderBy('id')->lockForUpdate()->get();
            [$actor, $device] = $this->actors->lockWithDevice($requester, 'adjust_stock');
            Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
            $batch = $this->adjustmentBatch($input['batch_id']);
            $product = $batch->product; $product->refreshForUpdate();
            if ($product->trashed()) { throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404); }
            $batch->refreshForUpdate(); $batch->setRelation('product', $product);
            [$delta, $change, $reason] = $this->correctionValues($product, $input, ($classification['type'] ?? '') === 'stock_count');
            try { $before = Quantity::parse($input['expected_qty'], weighted: $product->quantity_type === 'weight', allowNegative: true); }
            catch (\InvalidArgumentException|\OverflowException $e) { throw new ApiException('VALIDATION_ERROR', $e->getMessage(), 422, 'expected_qty'); }
            $snapshot = $this->correctionSnapshot($batch, $input['operation_id'], $change, Quantity::format($before, $product->quantity_type === 'weight'), $reason);
            if ($classification) $snapshot['classification'] = $classification;
            $fingerprint = SupervisorApprovalService::snapshotHash($snapshot);
            $operation = strtolower($input['operation_id']);
            $existing = StockMovement::where('operation_id', $operation)->first();
            if ($existing) {
                if ($existing->type !== ($classification['type'] ?? 'adjustment') || ! hash_equals($existing->request_fingerprint ?? '', $fingerprint)
                    || (int) $existing->user_id !== (int) $actor->id || (int) $existing->device_id !== (int) $device->id) {
                    throw new ApiException('OPERATION_ID_REUSED', 'This stock reference was already used with different details.', 409);
                }
                return ['movement' => $existing->load(['product', 'batch', 'user', 'device', 'approver']), 'replayed' => true];
            }
            if (($classification['type'] ?? '') === 'stock_count' && $product->department?->publicId() !== $classification['department_id']) {
                throw new ApiException('DEPARTMENT_CHANGED', 'This product moved to another department. Review its count again.', 409);
            }
            if ($batch->qty_remaining_atoms !== $before) { throw new ApiException('STOCK_CHANGED', 'Stock changed after review. Review the correction again and request fresh approval.', 409); }
            $after = $this->correctionBalance($before, $delta);
            $this->checkCorrectionAggregates($batch, $delta, $after);
            $write = function (?User $approver = null) use ($batch, $product, $after, $delta, $actor, $device, $operation, $fingerprint, $reason, $classification) {
                $batch->qty_remaining_atoms = $after; $batch->save();
                $receipt = $classification ? [...$classification, 'product_name'=>$product->name, 'quantity_type'=>$product->quantity_type, 'unit'=>$product->unit, 'cost_price'=>$batch->cost_price, 'loss_value'=>($classification['type'] === 'stock_loss' ? $this->lossCostValue(-$delta,$batch->cost_price,$product->quantity_type === 'weight') : 0), 'department_id'=>$product->department?->publicId(), 'department_name'=>$product->department?->name] : [];
                $movement = StockMovement::create(['product_id' => $product->id, 'batch_id' => $batch->id, 'type' => ($classification['type'] ?? 'adjustment'),
                    'qty_change_atoms' => $delta, 'qty_after_atoms' => $after, 'user_id' => $actor->id, 'device_id' => $device->id,
                    'operation_id' => $operation, 'request_fingerprint' => $fingerprint, 'receipt' => $receipt, 'reason' => $reason, 'approved_by' => $approver?->id ?? ($classification ? $actor->id : null)]);
                activity('stock')->performedOn($batch)->causedBy($actor)->event('stock_adjusted')->withProperties([
                    'operation_id' => $operation, 'movement' => $movement->publicId(), 'qty_change' => Quantity::format($delta, $product->quantity_type === 'weight'),
                    'qty_after' => Quantity::format($after, $product->quantity_type === 'weight'), 'approved_by' => $approver?->publicId()])->log('Stock corrected');
                return ['movement' => $movement->load(['product', 'batch', 'user', 'device', 'approver']), 'replayed' => false];
            };
            if ($actor->hasRole('owner')) { return $write(); }
            if (empty($input['approval_token'])) { throw new ApiException('SUPERVISOR_REQUIRED', 'An owner must approve every stockkeeper correction.', 403); }
            return app(SupervisorApprovalService::class)->execute($actor, $input['approval_token'], 'adjust_stock', $operation, $snapshot, $write);
        }, 3);
    }

    private function checkCorrectionAggregates(ProductBatch $batch, int $delta, int $after): void
    {
        try {
            ExactMath::add($this->checkedAtoms(ProductBatch::where('product_id', $batch->product_id)->sum('qty_remaining_atoms')), $delta);
            $eligible = $this->checkedAtoms($this->eligibleBatches(ProductBatch::where('product_id', $batch->product_id))->sum('qty_remaining_atoms'));
            $eligibleBefore = $this->isSellable($batch) ? $batch->qty_remaining_atoms : 0;
            $eligibleAfter = ! $batch->is_quarantined && ! $batch->is_recalled && ($batch->expiry_date === null || $batch->expiry_date->toDateString() >= now('Africa/Dar_es_Salaam')->toDateString()) ? $after : 0;
            ExactMath::add(ExactMath::add($eligible, -$eligibleBefore), $eligibleAfter);
        } catch (\OverflowException $e) { throw new ApiException('VALIDATION_ERROR', 'This correction exceeds the supported stock quantity.', 422, 'qty_change'); }
    }

    private function adjustmentBatch(string $id): ProductBatch
    {
        return (new ProductBatch)->resolveRouteBindingQuery(ProductBatch::with('product')->whereHas('product', fn (Builder $q) => $q->whereNull('deleted_at')), $id)->firstOrFail();
    }
    private function correctionValues(Product $product, array $input, bool $allowZero = false): array
    {
        try {
            $delta = Quantity::parse($input['qty_change'], weighted: $product->quantity_type === 'weight', allowNegative: true);
            if ($delta === 0 && !$allowZero) { throw new \InvalidArgumentException('Enter a non-zero stock change.'); }
        } catch (\InvalidArgumentException|\OverflowException $e) { throw new ApiException('VALIDATION_ERROR', $e->getMessage(), 422, 'qty_change'); }
        $reason = trim($input['reason']);
        if ($reason === '' || mb_strlen($reason) > 500) { throw new ApiException('VALIDATION_ERROR', 'Enter a correction reason up to 500 characters.', 422, 'reason'); }
        return [$delta, Quantity::format($delta, $product->quantity_type === 'weight'), $reason];
    }
    private function correctionBalance(int $before, int $delta): int
    {
        try { $after = ExactMath::add($before, $delta); }
        catch (\OverflowException $e) { throw new ApiException('VALIDATION_ERROR', $e->getMessage(), 422, 'qty_change'); }
        if ($after < 0) { throw new ApiException('INSUFFICIENT_STOCK', 'This correction would leave negative stock.', 400, 'qty_change'); }
        return $after;
    }
    private function correctionSnapshot(ProductBatch $batch, string $operation, string $change, string $before, string $reason): array
    {
        return ['operation_id' => strtolower($operation), 'batch_id' => $batch->publicId(), 'product_id' => $batch->product->publicId(), 'qty_change' => $change, 'qty_before' => $before, 'reason' => $reason];
    }
}
