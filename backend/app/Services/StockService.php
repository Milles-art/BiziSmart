<?php

namespace App\Services;

use App\Exceptions\ApiException;

use App\Models\{Product, ProductBatch, StockMovement, User};

use App\Services\Concerns\StockQueries;

use App\Support\{ExactMath, Money, Quantity};

use App\Tenancy\TenantContext;

use Illuminate\Database\Eloquent\Builder;

use Illuminate\Support\Facades\DB;

/**
 * Stock authority facade.
 *
 * Write paths live in dedicated services with their own lock ordering:
 *   - StockReceivingService: supplier deliveries (receive)
 *   - StockAdjustmentService: corrections/losses/counts (previewAdjustment, adjust, loss counts)
 *   - this class: sale allocation, return restoration, plus catalogue/alert reads.
 * The facade keeps every existing controller/test call site stable while the
 * implementations are separated by responsibility.
 */

class StockService
{
    use \App\Services\Concerns\SupplierStockReturns;
    use \App\Services\Concerns\OfflineStock;
    use StockQueries;
    public function __construct(private TenantContext $context, private StockReceivingService $receiving,
        private StockAdjustmentService $adjustments,
    )
    {
    }

    public function receive(User $actor, Product $product, array $input): array
    {
        return $this->receiving->receive($actor, $product, $input);
    }

    public function previewAdjustment(User $actor, array $input, array $classification = []): array
    {
        return $this->adjustments->previewAdjustment($actor, $input, $classification);
    }

    public function adjust(User $requester,
        #[\SensitiveParameter]
        array $input, array $classification = []): array
    {
        return $this->adjustments->adjust($requester, $input, $classification);
    }

    public function previewLossCount(User $actor, array $input, bool $count): array
    {
        return $this->adjustments->previewLossCount($actor, $input, $count);
    }

    public function commitLossCount(User $actor,
        #[\SensitiveParameter]
        array $input, bool $count): array
    {
        return $this->adjustments->commitLossCount($actor, $input, $count);
    }

    public function losses(array $filters): array
    {
        return $this->adjustments->losses($filters);
    }

    /** SaleService holds the actor, shop, till and product locks before allocation. */
    public function allocateSale(User $actor, Product $product, \App\Models\Sale $sale, int $atoms, int $lineIndex,
        int $price, ?array $pricingAllocations = null
    ): void
    {
        $actor->assertCurrentBusiness();
        $product->assertCurrentBusiness();
        $sale->assertCurrentBusiness();
        if (DB::transactionLevel() < 1 || $atoms <= 0) {
            throw new \LogicException('Positive sale allocations require a domain transaction.');
        }
        if ($pricingAllocations !== null) {
            $allocated = 0;
            foreach ($pricingAllocations as $quote) {
                $take = Quantity::parse($quote['qty_atoms']);
                if ($take < 1 || $take !== Quantity::parse($quote['base_qty'], $product->quantity_type === 'weight'))
                    throw new \LogicException('Invalid authoritative pricing allocation.');
                $batch = (new ProductBatch)->resolveRouteBindingQuery($this->eligibleBatches(
                        ProductBatch::where('product_id', $product->id)
                    ), $quote['batch_id'])->lockForUpdate()->first();
                if (! $batch || $batch->qty_remaining_atoms < $take) throw new ApiException(
                    'SALE_CHANGED', 'The quoted batch allocation changed. Review the sale again.', 409
                );
                $allocated = ExactMath::add($allocated, $take);
                if ($allocated > $atoms) throw new \LogicException(
                    'A pricing allocation exceeds its logical quantity.'
                );
                $batch->qty_remaining_atoms -= $take;
                $batch->save();
                \App\Models\SaleItem::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'batch_id' => $batch
                            ->id, 'line_index' => $lineIndex, 'qty_atoms' => $take, 'unit_price' => Money
                            ::amount($quote['effective_unit_price']), 'cost_price' => $batch->cost_price]);
                StockMovement::create(['product_id' => $product->id, 'batch_id' => $batch->id, 'sale_id' => $sale
                            ->id, 'type' => 'sale', 'qty_change_atoms' => -$take,
                        'qty_after_atoms' => $batch->qty_remaining_atoms,
                        'user_id' => $actor->id, 'device_id' => $actor->currentAccessToken(
                        )->device_id, 'operation_id' => (string)\Illuminate\Support\Str::uuid(
                        ), 'receipt' => ['sale_id' => $sale->publicId(), 'line_index' => $lineIndex,
                            'pricing_offer_id' => $quote['offer_id'], 'gross_total' => $quote['gross_total'],
                            'net_total' => $quote['net_total'], 'automatic_discount' => $quote['automatic_discount']
                        ]
                    ]);
            }
            if ($allocated !== $atoms) throw new \LogicException(
                'Pricing allocations must exactly cover the logical quantity.'
            );
            return;
        }
        $batches = $this->eligibleBatches(ProductBatch::where('product_id', $product->id))->orderByRaw(
            'expiry_date IS NULL'
        )->orderBy('expiry_date')->orderByRaw('CASE WHEN expiry_date IS NULL THEN received_at ELSE NULL END')->orderBy(
            'id'
        )->lockForUpdate()->get();
        $remaining = $atoms;
        foreach ($batches as $batch) {
            if ($remaining === 0) {
                break;
            }
            $taken = min($remaining, $batch->qty_remaining_atoms);
            $batch->qty_remaining_atoms -= $taken;
            $batch->save();
            \App\Models\SaleItem::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'batch_id' => $batch
                        ->id, 'line_index' => $lineIndex, 'qty_atoms' => $taken, 'unit_price' => $price,
                    'cost_price' => $batch->cost_price
                ]);
            StockMovement::create(['product_id' => $product->id, 'batch_id' => $batch->id, 'sale_id' => $sale->id,
                    'type' => 'sale', 'qty_change_atoms' => -$taken, 'qty_after_atoms' => $batch->qty_remaining_atoms,
                    'user_id' => $actor->id, 'device_id' => $actor->currentAccessToken()->device_id,
                    'operation_id' => (string)\Illuminate\Support\Str::uuid(), 'receipt' => ['sale_id' => $sale
                            ->publicId(), 'line_index' => $lineIndex]
                ]);
            $remaining -= $taken;
        }
        if ($remaining > 0) {
            throw new ApiException('INSUFFICIENT_STOCK', 'Not enough sellable stock for ' . $product->name . '.',
                400, 'items', ['product_id' => $product->publicId(), 'available_qty' => Quantity::format(
                        $atoms - $remaining, $product->quantity_type === 'weight'
                    )]
            );
        }
    }

    /** ReturnService serializes the business and supplies a locked original allocation. */
    public function restoreReturn(User $actor, \App\Models\SaleReturn $return, \App\Models\SaleItem $item,
        int $atoms, bool $damaged
    ): ProductBatch
    {
        $actor->assertCurrentBusiness();
        $return->assertCurrentBusiness();
        $item->assertCurrentBusiness();
        if (DB::transactionLevel() < 1 || $atoms < 1 || (int)$return->sale_id !== (int)$item->sale_id)
            throw new \LogicException('Stock returns require their original sale transaction.');
        $product = $item->product;
        $product->refreshForUpdate();
        $source = $item->batch;
        $source->refreshForUpdate();
        $quarantine = $damaged || $source->is_quarantined || $source->is_recalled || ($source->expiry_date !== null
                && $source->expiry_date->toDateString() < now('Africa/Dar_es_Salaam')->toDateString(
            ));
        $target = $source;
        if ($quarantine) {
            $operation = (string)\Illuminate\Support\Str::uuid();
            $target = ProductBatch::create(['product_id' => $product->id, 'source_batch_id' => $source->id,
                    'batch_number' => 'RETURN-' . substr($return->publicId(), 4), 'expiry_date' => $source->expiry_date,
                    'qty_remaining_atoms' => 0, 'cost_price' => $source->cost_price, 'received_at' => now(
                    ), 'is_quarantined' => true, 'is_recalled' => $source->is_recalled, 'operation_id' => $operation,
                    'request_fingerprint' => SupervisorApprovalService::snapshotHash(['return_id' => $return->publicId(
                            ), 'source_item' => $item->publicId()]), 'receipt' => ['operation_id' => $operation,
                        'qty' => Quantity::format($atoms, $product->quantity_type === 'weight'), 'base_qty' => Quantity
                            ::format($atoms, $product->quantity_type === 'weight'), 'packaging_id' => null,
                        'packaging_name' => null, 'packaging_version' => null, 'conversion_atoms' => (string)(
                            $product->quantity_type === 'weight' ? 1000 : 1
                        ), 'return_id' => $return->publicId(), 'source_batch_id' => $source->publicId(
                        )
                    ]
                ]);
            $target->refreshForUpdate();
        }
        try {
            $after = ExactMath::add($target->qty_remaining_atoms, $atoms);
            $held = 0;
            $eligible = 0;
            foreach (ProductBatch::where('product_id', $product->id)->orderBy('id')->lockForUpdate(
                )->get() as $balance) {
                $held = ExactMath::add($held, $balance->qty_remaining_atoms);
                if ($this->isSellable($balance)) $eligible = ExactMath::add($eligible, $balance->qty_remaining_atoms);
            }
            ExactMath::add($held, $atoms);
            if (! $quarantine) ExactMath::add($eligible, $atoms);
        } catch (\OverflowException $e) {
            throw new ApiException('STOCK_QUANTITY_OVERFLOW', 'This return exceeds the supported stock quantity.',
                409
            );
        }
        $target->qty_remaining_atoms = $after;
        $target->save();
        StockMovement::create(['product_id' => $product->id, 'batch_id' => $target->id, 'sale_id' => $return->sale_id,
                'type' => 'return', 'qty_change_atoms' => $atoms, 'qty_after_atoms' => $after, 'user_id' => $actor
                    ->id, 'device_id' => $actor->currentAccessToken()->device_id, 'approved_by' => $return->approved_by,
                'operation_id' => (string)\Illuminate\Support\Str::uuid(), 'receipt' => ['return_id' => $return
                        ->publicId(), 'source_batch_id' => $source->publicId(), 'sale_item_id' => $item->publicId(
                    )]
            ]);
        return $target;
    }

    public function catalogue(Builder $query, array $filters = []): Builder
    {
        $today = now('Africa/Dar_es_Salaam')->toDateString();
        $query->withSum(['batches as stock_atoms' => fn (Builder $batches) => $this->eligibleBatches(
                    $batches, $today
                )], 'qty_remaining_atoms')->withSum('batches as held_atoms', 'qty_remaining_atoms')->withMin(
            ['batches as eligible_expiry' => fn (Builder $batches) => $this->eligibleBatches(
                    $batches, $today
                )], 'expiry_date'
        );
        if ($filters['low_stock'] ?? false) {
            $this->lowStock($query, $today);
        }
        if (isset($filters['expiring_in'])) {
            $end = \Carbon\CarbonImmutable::parse($today, 'Africa/Dar_es_Salaam')->addDays(
                (int)$filters['expiring_in']
            )->toDateString();
            $query->whereHas('batches', fn (Builder $batches) => $this->eligibleBatches($batches, $today)->whereNotNull(
                    'expiry_date'
                )->where('expiry_date', '<=', $end));
        }
        return $query;
    }

    public function lowStock(Builder $query, ?string $today = null): Builder
    {
        // toBase applies the fail-closed tenant scope before collecting SQL and its bindings.
        $sum = $this->eligibleBatches(ProductBatch::query(), $today)->whereColumn('product_batches.product_id',
            'products.id'
        )->selectRaw('COALESCE(SUM(qty_remaining_atoms), 0)')->toBase();
        return $query->whereRaw('(' . $sum->toSql() . ') <= products.reorder_level_atoms', $sum->getBindings(
            ));
    }

    public function reorderAlerts(): Builder
    {
        return $this->catalogue(Product::with(['activePackagings.product', 'department'])->where(
                'is_active', true
            ), ['low_stock' => true])->orderBy('name')->orderBy('id');
    }

    public function expiryAlerts(int $days = 30): Builder
    {
        $today = now('Africa/Dar_es_Salaam')->toDateString();
        $end = \Carbon\CarbonImmutable::parse($today, 'Africa/Dar_es_Salaam')->addDays($days)->toDateString(
        );
        return $this->eligibleBatches(ProductBatch::with('product')->whereHas('product', fn (
                    Builder $products
                ) => $products->where('is_active', true)->whereNull('deleted_at')), $today)->whereNotNull(
            'expiry_date'
        )->where('expiry_date', '<=', $end)->orderBy('expiry_date')->orderBy('id');
    }

    public function summary(Product $product): array
    {
        $product->assertCurrentBusiness();
        if (! array_key_exists('stock_atoms', $product->getAttributes())) {
            $product = $this->catalogue(Product::whereKey($product->id))->firstOrFail();
        }
        $weighted = $product->quantity_type === 'weight';
        return ['stock' => Quantity::format($this->checkedAtoms($product->stock_atoms), $weighted),
            'stock_total' => Quantity::format($this->checkedAtoms($product->held_atoms), $weighted),
            'nearest_expiry' => $product->eligible_expiry
        ];
    }

    public function movements(Product $product, array $filters = []): Builder
    {
        $product->assertCurrentBusiness();
        $query = StockMovement::with(['product', 'batch',
                'user:id,business_id,public_id', 'device:id,business_id,public_id',
                'approver:id,business_id,public_id'
            ])->where('product_id', $product->id);
        if (isset($filters['batch_id'])) {
            $batch = (new ProductBatch)->resolveRouteBindingQuery(ProductBatch::where('product_id', $product->id),
                $filters['batch_id']
            )->firstOrFail();
            $query->where('batch_id', $batch->id);
        }
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}