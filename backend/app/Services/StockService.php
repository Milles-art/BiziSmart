<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{Business, Device, Product, ProductBatch, ProductPackaging, StockMovement, User};
use App\Support\{ExactMath, Money, Quantity};
use App\Tenancy\TenantContext;
use Illuminate\Auth\{Access\AuthorizationException, AuthenticationException};
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;

class StockService
{
    use \App\Services\Concerns\SupplierStockReturns;
    use \App\Services\Concerns\OfflineStock;
    use \App\Services\Concerns\StockLossCounts;
    public function __construct(private TenantContext $context) {}

    public function receive(User $actor, Product $product, array $input): array
    {
        $product->assertCurrentBusiness();
        return DB::transaction(function () use ($actor, $product, $input) {
            $actor->refreshForUpdate(); $actor->unsetRelation('roles')->unsetRelation('permissions');
            Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
            if (! $actor->is_active || ! $actor->can('receive_stock')) { throw new AuthorizationException; }
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
            $users = User::whereIn('id', array_unique(array_filter([$requester->id, $grant?->approver_id])))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($requester->id);
            $token = $requester->currentAccessToken();
            $current = $token ? \App\Models\PersonalAccessToken::whereKey($token->id)->where('tokenable_id', $requester->id)->lockForUpdate()->first() : null;
            $device = $current?->device_id ? Device::whereKey($current->device_id)->lockForUpdate()->first() : null;
            if (! $actor || ! $actor->is_active || $actor->must_change_pin || $actor->getRoleNames()->count() !== 1
                || ($actor->hasRole('owner') && ! $actor->ownerAuthenticationReady()) || ! $current || $current->expires_at?->isPast() || $device?->status !== 'approved') { throw new AuthenticationException; }
            if (! $actor->can('adjust_stock')) { throw new AuthorizationException; }
            $actor->withAccessToken($current);
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

    public function eligibleBatches(Builder $query, ?string $today = null): Builder
    {
        $today ??= now('Africa/Dar_es_Salaam')->toDateString();
        return $query->where('qty_remaining_atoms', '>', 0)->where('is_quarantined', false)->where('is_recalled', false)
            ->where(fn (Builder $dates) => $dates->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today));
    }

    /** SaleService holds the actor, shop, till and product locks before allocation. */
    public function allocateSale(User $actor, Product $product, \App\Models\Sale $sale, int $atoms, int $lineIndex, int $price, ?array $pricingAllocations=null): void
    {
        $actor->assertCurrentBusiness(); $product->assertCurrentBusiness(); $sale->assertCurrentBusiness();
        if (DB::transactionLevel() < 1 || $atoms <= 0) { throw new \LogicException('Positive sale allocations require a domain transaction.'); }
        if($pricingAllocations!==null){
            $allocated=0;
            foreach($pricingAllocations as$quote){
                $take=Quantity::parse($quote['qty_atoms']);
                if($take<1||$take!==Quantity::parse($quote['base_qty'],$product->quantity_type==='weight'))throw new \LogicException('Invalid authoritative pricing allocation.');
                $batch=(new ProductBatch)->resolveRouteBindingQuery($this->eligibleBatches(ProductBatch::where('product_id',$product->id)),$quote['batch_id'])->lockForUpdate()->first();
                if(!$batch||$batch->qty_remaining_atoms<$take)throw new ApiException('SALE_CHANGED','The quoted batch allocation changed. Review the sale again.',409);
                $allocated=ExactMath::add($allocated,$take);if($allocated>$atoms)throw new \LogicException('A pricing allocation exceeds its logical quantity.');
                $batch->qty_remaining_atoms-=$take;$batch->save();
                \App\Models\SaleItem::create(['sale_id'=>$sale->id,'product_id'=>$product->id,'batch_id'=>$batch->id,'line_index'=>$lineIndex,'qty_atoms'=>$take,'unit_price'=>Money::amount($quote['effective_unit_price']),'cost_price'=>$batch->cost_price]);
                StockMovement::create(['product_id'=>$product->id,'batch_id'=>$batch->id,'sale_id'=>$sale->id,'type'=>'sale','qty_change_atoms'=>-$take,'qty_after_atoms'=>$batch->qty_remaining_atoms,'user_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'operation_id'=>(string)\Illuminate\Support\Str::uuid(),'receipt'=>['sale_id'=>$sale->publicId(),'line_index'=>$lineIndex,'pricing_offer_id'=>$quote['offer_id'],'gross_total'=>$quote['gross_total'],'net_total'=>$quote['net_total'],'automatic_discount'=>$quote['automatic_discount']]]);
            }
            if($allocated!==$atoms)throw new \LogicException('Pricing allocations must exactly cover the logical quantity.');
            return;
        }
        $batches = $this->eligibleBatches(ProductBatch::where('product_id', $product->id))
            ->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN received_at ELSE NULL END')->orderBy('id')->lockForUpdate()->get();
        $remaining = $atoms;
        foreach ($batches as $batch) {
            if ($remaining === 0) { break; }
            $taken = min($remaining, $batch->qty_remaining_atoms);
            $batch->qty_remaining_atoms -= $taken; $batch->save();
            \App\Models\SaleItem::create(['sale_id'=>$sale->id, 'product_id'=>$product->id, 'batch_id'=>$batch->id,
                'line_index'=>$lineIndex, 'qty_atoms'=>$taken, 'unit_price'=>$price,'cost_price'=>$batch->cost_price]);
            StockMovement::create(['product_id'=>$product->id, 'batch_id'=>$batch->id, 'sale_id'=>$sale->id, 'type'=>'sale',
                'qty_change_atoms'=>-$taken, 'qty_after_atoms'=>$batch->qty_remaining_atoms, 'user_id'=>$actor->id,
                'device_id'=>$actor->currentAccessToken()->device_id, 'operation_id'=>(string) \Illuminate\Support\Str::uuid(),
                'receipt'=>['sale_id'=>$sale->publicId(), 'line_index'=>$lineIndex]]);
            $remaining -= $taken;
        }
        if ($remaining > 0) { throw new ApiException('INSUFFICIENT_STOCK', 'Not enough sellable stock for '.$product->name.'.', 400, 'items',
            ['product_id'=>$product->publicId(), 'available_qty'=>Quantity::format($atoms - $remaining, $product->quantity_type === 'weight')]); }
    }

    /** ReturnService serializes the business and supplies a locked original allocation. */
    public function restoreReturn(User $actor, \App\Models\SaleReturn $return, \App\Models\SaleItem $item, int $atoms, bool $damaged): ProductBatch
    {
        $actor->assertCurrentBusiness(); $return->assertCurrentBusiness(); $item->assertCurrentBusiness();
        if (DB::transactionLevel() < 1 || $atoms < 1 || (int)$return->sale_id !== (int)$item->sale_id) throw new \LogicException('Stock returns require their original sale transaction.');
        $product = $item->product; $product->refreshForUpdate(); $source = $item->batch; $source->refreshForUpdate();
        $quarantine = $damaged || $source->is_quarantined || $source->is_recalled || ($source->expiry_date !== null && $source->expiry_date->toDateString() < now('Africa/Dar_es_Salaam')->toDateString());
        $target = $source;
        if ($quarantine) {
            $operation = (string) \Illuminate\Support\Str::uuid();
            $target = ProductBatch::create(['product_id'=>$product->id,'source_batch_id'=>$source->id,'batch_number'=>'RETURN-'.substr($return->publicId(),4),
                'expiry_date'=>$source->expiry_date,'qty_remaining_atoms'=>0,'cost_price'=>$source->cost_price,'received_at'=>now(),'is_quarantined'=>true,'is_recalled'=>$source->is_recalled,
                'operation_id'=>$operation,'request_fingerprint'=>SupervisorApprovalService::snapshotHash(['return_id'=>$return->publicId(),'source_item'=>$item->publicId()]),
                'receipt'=>['operation_id'=>$operation,'qty'=>Quantity::format($atoms,$product->quantity_type==='weight'),'base_qty'=>Quantity::format($atoms,$product->quantity_type==='weight'),
                    'packaging_id'=>null,'packaging_name'=>null,'packaging_version'=>null,'conversion_atoms'=>(string)($product->quantity_type==='weight'?1000:1),
                    'return_id'=>$return->publicId(),'source_batch_id'=>$source->publicId()]]);
            $target->refreshForUpdate();
        }
        try { $after = ExactMath::add($target->qty_remaining_atoms,$atoms); $held=0;$eligible=0;
            foreach(ProductBatch::where('product_id',$product->id)->orderBy('id')->lockForUpdate()->get() as $balance) {
                $held=ExactMath::add($held,$balance->qty_remaining_atoms);
                if($this->isSellable($balance))$eligible=ExactMath::add($eligible,$balance->qty_remaining_atoms);
            }
            ExactMath::add($held,$atoms);if(!$quarantine)ExactMath::add($eligible,$atoms); }
        catch (\OverflowException $e) { throw new ApiException('STOCK_QUANTITY_OVERFLOW','This return exceeds the supported stock quantity.',409); }
        $target->qty_remaining_atoms=$after; $target->save();
        StockMovement::create(['product_id'=>$product->id,'batch_id'=>$target->id,'sale_id'=>$return->sale_id,'type'=>'return','qty_change_atoms'=>$atoms,'qty_after_atoms'=>$after,
            'user_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'approved_by'=>$return->approved_by,'operation_id'=>(string)\Illuminate\Support\Str::uuid(),
            'receipt'=>['return_id'=>$return->publicId(),'source_batch_id'=>$source->publicId(),'sale_item_id'=>$item->publicId()]]);
        return $target;
    }

    public function catalogue(Builder $query, array $filters = []): Builder
    {
        $today = now('Africa/Dar_es_Salaam')->toDateString();
        $query->withSum(['batches as stock_atoms' => fn (Builder $batches) => $this->eligibleBatches($batches, $today)], 'qty_remaining_atoms')
            ->withSum('batches as held_atoms', 'qty_remaining_atoms')
            ->withMin(['batches as eligible_expiry' => fn (Builder $batches) => $this->eligibleBatches($batches, $today)], 'expiry_date');
        if ($filters['low_stock'] ?? false) { $this->lowStock($query, $today); }
        if (isset($filters['expiring_in'])) {
            $end = \Carbon\CarbonImmutable::parse($today, 'Africa/Dar_es_Salaam')->addDays((int) $filters['expiring_in'])->toDateString();
            $query->whereHas('batches', fn (Builder $batches) => $this->eligibleBatches($batches, $today)->whereNotNull('expiry_date')->where('expiry_date', '<=', $end));
        }
        return $query;
    }

    public function lowStock(Builder $query, ?string $today = null): Builder
    {
        // toBase applies the fail-closed tenant scope before collecting SQL and its bindings.
        $sum = $this->eligibleBatches(ProductBatch::query(), $today)->whereColumn('product_batches.product_id', 'products.id')
            ->selectRaw('COALESCE(SUM(qty_remaining_atoms), 0)')->toBase();
        return $query->whereRaw('('.$sum->toSql().') <= products.reorder_level_atoms', $sum->getBindings());
    }

    public function reorderAlerts(): Builder
    {
        return $this->catalogue(Product::with(['activePackagings.product', 'department'])->where('is_active', true), ['low_stock' => true])
            ->orderBy('name')->orderBy('id');
    }

    public function expiryAlerts(int $days = 30): Builder
    {
        $today = now('Africa/Dar_es_Salaam')->toDateString();
        $end = \Carbon\CarbonImmutable::parse($today, 'Africa/Dar_es_Salaam')->addDays($days)->toDateString();
        return $this->eligibleBatches(ProductBatch::with('product')->whereHas('product', fn (Builder $products) => $products->where('is_active', true)->whereNull('deleted_at')), $today)
            ->whereNotNull('expiry_date')->where('expiry_date', '<=', $end)->orderBy('expiry_date')->orderBy('id');
    }

    public function summary(Product $product): array
    {
        $product->assertCurrentBusiness();
        if (! array_key_exists('stock_atoms', $product->getAttributes())) { $product = $this->catalogue(Product::whereKey($product->id))->firstOrFail(); }
        $weighted = $product->quantity_type === 'weight';
        return ['stock' => Quantity::format($this->checkedAtoms($product->stock_atoms), $weighted),
            'stock_total' => Quantity::format($this->checkedAtoms($product->held_atoms), $weighted), 'nearest_expiry' => $product->eligible_expiry];
    }

    private function checkedAtoms(mixed $value): int
    {
        try { return Quantity::parse((string) ($value ?? 0), allowNegative: true); }
        catch (\InvalidArgumentException|\OverflowException $e) { throw new ApiException('STOCK_QUANTITY_OVERFLOW', 'Recorded stock exceeds the supported quantity range. Contact the shop owner.', 409); }
    }

    public function isSellable(ProductBatch $batch): bool
    {
        $batch->assertCurrentBusiness();
        return $batch->qty_remaining_atoms > 0 && ! $batch->is_quarantined && ! $batch->is_recalled
            && ($batch->expiry_date === null || $batch->expiry_date->toDateString() >= now('Africa/Dar_es_Salaam')->toDateString());
    }

    public function movements(Product $product, array $filters = []): Builder
    {
        $product->assertCurrentBusiness();
        $query = StockMovement::with(['product', 'batch', 'user:id,business_id,public_id', 'device:id,business_id,public_id', 'approver:id,business_id,public_id'])->where('product_id', $product->id);
        if (isset($filters['batch_id'])) {
            $batch = (new ProductBatch)->resolveRouteBindingQuery(ProductBatch::where('product_id', $product->id), $filters['batch_id'])->firstOrFail();
            $query->where('batch_id', $batch->id);
        }
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
