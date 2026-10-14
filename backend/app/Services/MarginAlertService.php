<?php
namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Support\ExactMath;
use App\Support\Money;
use App\Support\Quantity;
use App\Tenancy\TenantContext;

/**
 * S22 (F10.12): warn-only margin alerts on quoted sale lines.
 *
 * Compares each batch segment's final customer-paid net against that batch's
 * actual recorded cost. Warnings never change prices and never block a sale;
 * optional blocking/override policy remains undecided (D023) and is not built.
 *
 * Audience: actors with view_buying_price (owners, stockkeepers) see cost
 * detail; actors with approve_variance but no cost view (managers) see
 * redacted warnings without any cost figures; everyone else (cashiers) sees
 * nothing at all — not even an empty list. Stockkeepers cannot reach sale
 * quotes because they lack the sell permission.
 */
class MarginAlertService
{
    public function __construct(private TenantContext $context, private StockService $stock) {}

    public function warn(User $actor, array $lines): ?array
    {
        $detailed = $actor->can('view_buying_price');
        if (! $detailed && ! $actor->can('approve_variance')) {
            return null;
        }
        $warnings = [];
        foreach ($lines as $index => $line) {
            foreach ($this->segments($line) as $segment) {
                if ($segment['net'] < $segment['cost']) {
                    $entry = ['code' => 'MARGIN_BELOW_COST', 'line_index' => $index,
                        'product_id' => $line['product_id'], 'product_name' => $line['name'] ?? '',
                        'batch_id' => $segment['batch']->publicId(), 'batch_number' => $segment['batch']->batch_number,
                        'base_qty' => $segment['base_qty'], 'net_total' => $segment['net']];
                    if ($detailed) {
                        $entry['cost_total'] = $segment['cost'];
                        $entry['shortfall'] = $segment['cost'] - $segment['net'];
                    }
                    $warnings[] = $entry;
                }
            }
        }
        return $warnings;
    }

    /**
     * Split a final quoted line into per-batch (net, cost) pairs. Nets sum
     * exactly to the line total via cumulative proportional rounding, mirroring
     * SaleDiscountCalculator. Costs use each batch's recorded cost_price, so
     * split-batch sales compare against their actual costs.
     */
    private function segments(array $line): array
    {
        $product = (new Product)->resolveRouteBinding($line['product_id']);
        if (! $product) {
            return [];
        }
        $weighted = $product->quantity_type === 'weight';
        $final = Money::amount($line['line_total']);
        if (isset($line['pricing_allocations']) && is_array($line['pricing_allocations']) && $line['pricing_allocations'] !== []) {
            return $this->quotedSegments($product, $line['pricing_allocations'], $final, $weighted);
        }
        return $this->fefoSegments($product, $line, $final, $weighted);
    }

    private function quotedSegments(Product $product, array $allocations, int $final, bool $weighted): array
    {
        $divisor = 0;
        foreach ($allocations as $allocation) {
            $divisor = ExactMath::add($divisor, Money::amount($allocation['net_total']));
        }
        $segments = [];
        $position = 0;
        $previous = 0;
        foreach ($allocations as $allocation) {
            $atoms = Quantity::parse($allocation['qty_atoms']);
            $batch = $this->batch($product, $allocation['batch_id']);
            if ($atoms < 1 || ! $batch) {
                continue;
            }
            if ($divisor > 0) {
                $position = ExactMath::add($position, Money::amount($allocation['net_total']));
                $allocated = ExactMath::roundProportion($final, $position, $divisor);
                $net = $allocated - $previous;
                $previous = $allocated;
            } else {
                $net = 0;
            }
            $segments[] = ['batch' => $batch, 'base_qty' => Quantity::format($atoms, $weighted), 'net' => $net, 'cost' => $this->cost($atoms, $batch->cost_price, $weighted)];
        }
        return $segments;
    }

    /** Read-only FEFO projection for lines quoted without pricing allocations (no active pricing rules). */
    private function fefoSegments(Product $product, array $line, int $final, bool $weighted): array
    {
        $needed = Quantity::parse($line['base_qty'], $weighted);
        if ($needed < 1) {
            return [];
        }
        $takes = [];
        $remaining = $needed;
        $batches = $this->stock->eligibleBatches(ProductBatch::where('product_id', $product->id))
            ->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN received_at ELSE NULL END')->orderBy('id')->get();
        foreach ($batches as $batch) {
            if ($remaining === 0) {
                break;
            }
            $take = min($remaining, $batch->qty_remaining_atoms);
            if ($take < 1) {
                continue;
            }
            $takes[] = ['batch' => $batch, 'atoms' => $take];
            $remaining -= $take;
        }
        $segments = [];
        $position = 0;
        $previous = 0;
        foreach ($takes as $take) {
            $position = ExactMath::add($position, $take['atoms']);
            $allocated = ExactMath::roundProportion($final, $position, $needed);
            $net = $allocated - $previous;
            $previous = $allocated;
            $segments[] = ['batch' => $take['batch'], 'base_qty' => Quantity::format($take['atoms'], $weighted), 'net' => $net, 'cost' => $this->cost($take['atoms'], $take['batch']->cost_price, $weighted)];
        }
        return $segments;
    }

    private function batch(Product $product, string $batchId): ?ProductBatch
    {
        return (new ProductBatch)->resolveRouteBindingQuery(
            $this->stock->eligibleBatches(ProductBatch::where('product_id', $product->id)), $batchId)->first();
    }

    private function cost(int $atoms, int $costPrice, bool $weighted): int
    {
        try {
            return Money::lineTotal($atoms, $costPrice, $weighted);
        } catch (\InvalidArgumentException|\OverflowException $e) {
            throw new ApiException('VALIDATION_ERROR', 'A batch cost exceeds the supported exact amount.', 422);
        }
    }
}
