<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Branch;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Support\{ExactMath, Money, Quantity, ReportMath, ReportPeriod};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Owner sales analytics over one bounded reporting window: payment-method
 * split, hourly trading buckets, ranked sellers and slow-moving stock. Money is
 * integer TSh and quantity is exact; returns reverse their own sale through the
 * same cumulative proportion rules as the profit report, and nothing is estimated.
 */
class SalesAnalyticsService
{
    private const METHODS = ['cash' => 'Cash', 'mpesa' => 'Mobile money', 'credit' => 'Customer credit'];

    public function __construct(private RefundCalculator $refunds) {}

    public function build(array $filters): array
    {
        return DB::transaction(function () use ($filters) {
            $period = new ReportPeriod($filters['from'], $filters['to']);
            $branch = isset($filters['branch_id']) ? (new Branch)->resolveRouteBinding($filters['branch_id']) : null;
            if (isset($filters['branch_id']) && ! $branch) { abort(404); }
            $methods = [];
            foreach (self::METHODS as $key => $label) {
                $methods[$key] = ['method' => $key, 'label' => $label, 'sales' => 0, 'refunds' => 0, 'net' => 0];
            }
            $hourly = [];
            foreach (range(0, 23) as $hour) {
                $hourly[$hour] = ['hour' => $hour, 'label' => sprintf('%02d:00', $hour), 'sales' => 0, 'refunds' => 0, 'net' => 0];
            }
            $products = [];
            $soldIds = [];

            $sales = $this->window(Sale::query(), $period);
            if ($branch) { $sales->where('branch_id', $branch->id); }
            $this->bounded($sales);
            $sales->with(['payments', 'items.batch'])->chunkById(100, function ($rows) use (&$methods, &$hourly, &$products, &$soldIds) {
                foreach ($rows as $sale) {
                    $hour = (int) ($sale->occurred_at ?? $sale->created_at)->copy()->timezone('Africa/Dar_es_Salaam')->format('G');
                    $this->add($hourly[$hour], 'sales', $sale->total);
                    foreach ($sale->payments as $payment) {
                        $method = $payment->method;
                        if (! array_key_exists($method, $methods)) { throw new \LogicException('Unsupported recorded payment method.'); }
                        $this->add($methods[$method], 'sales', (int) $payment->amount);
                    }
                    $cumulative = 0;
                    $previousVat = 0;
                    foreach ($sale->items->groupBy('line_index') as $index => $items) {
                        $item = $items->first();
                        $line = $sale->lines[$index];
                        $weighted = ($line['quantity_type'] ?? null) === 'weight';
                        $entry = $products[$item->product_id] ?? $this->emptyProduct();
                        foreach ($items as $allocation) {
                            $entry['qty_atoms'] = ExactMath::add($entry['qty_atoms'], $allocation->qty_atoms);
                            $entry['cost'] = ReportMath::add($entry['cost'], $this->cost($allocation, $weighted));
                        }
                        // The immutable sale line snapshot is the authoritative charged amount.
                        $entry['revenue'] = ReportMath::add($entry['revenue'], (int) ($line['line_total'] ?? 0));
                        $cumulative = ReportMath::add($cumulative, $line['line_total']);
                        $vat = $sale->total > 0 ? ExactMath::roundProportion($sale->vat_amount, $cumulative, $sale->total) : 0;
                        $entry['vat'] = ReportMath::add($entry['vat'], $vat - $previousVat);
                        $previousVat = $vat;
                        $entry['sales_count']++;
                        $products[$item->product_id] = $entry;
                        $soldIds[$item->product_id] = true;
                    }
                }
            });

            $returns = $this->window(SaleReturn::query(), $period);
            if ($branch) { $returns->whereIn('sale_id', Sale::where('branch_id', $branch->id)->select('id')); }
            $this->bounded($returns);
            $returns->chunkById(100, function ($rows) use (&$methods, &$hourly, &$products) {
                foreach ($rows as $return) {
                    $hour = (int) $return->created_at->copy()->timezone('Africa/Dar_es_Salaam')->format('G');
                    $this->add($hourly[$hour], 'refunds', $return->refund_total);
                    $originalSale = $return->sale_id ? Sale::find($return->sale_id) : null;
                    $beforeRefund = Quantity::parse((string) SaleReturn::where('sale_id', $return->sale_id)->where('id', '<', $return->id)->sum('refund_total'));
                    $refundedVat = ReportMath::reversal($originalSale->vat_amount, $originalSale->total, $beforeRefund, $return->refund_total);
                    $cumulative = 0;
                    $previousVat = 0;
                    foreach ($return->lines as $line) {
                        // Historical products remain tenant-scoped and may be archived.
                        $product = (new Product)->resolveRouteBindingQuery(Product::withTrashed(), $line['product_id'])->firstOrFail();
                        $entry = $products[$product->id] ?? $this->emptyProduct();
                        $entry['revenue'] = ReportMath::add($entry['revenue'], -$line['amount']);
                        $cumulative = ReportMath::add($cumulative, $line['amount']);
                        $vat = $return->refund_total > 0 ? ExactMath::roundProportion($refundedVat, $cumulative, $return->refund_total) : 0;
                        $entry['vat'] = ReportMath::add($entry['vat'], -($vat - $previousVat));
                        $previousVat = $vat;
                        $products[$product->id] = $entry;
                    }
                    foreach (SaleReturnItem::where('sale_return_id', $return->id)->get() as $returned) {
                        $original = SaleItem::find($returned->sale_item_id);
                        if (! $original) { throw new \LogicException('Missing original sale allocation.'); }
                        // Cumulative proportion: earlier returns of this line already reversed part of it.
                        $already = (int) SaleReturnItem::where('sale_item_id', $original->id)
                            ->where('sale_return_id', '<', $return->id)->sum('qty_atoms');
                        $line = $originalSale?->lines[$original->line_index] ?? [];
                        $weighted = ($line['quantity_type'] ?? null) === 'weight';
                        $entry = $products[$returned->product_id] ?? $this->emptyProduct();
                        $entry['cost'] = ReportMath::add($entry['cost'], -ReportMath::reversal($this->cost($original, $weighted), $original->qty_atoms, $already, $returned->qty_atoms));
                        $entry['qty_atoms'] = ExactMath::add($entry['qty_atoms'], -$returned->qty_atoms);
                        $products[$returned->product_id] = $entry;
                    }
                    if ($return->cash_refund > 0) {
                        $this->add($methods['cash'], 'refunds', (int) $return->cash_refund);
                    }
                    if ($return->mobile_refund > 0) {
                        $this->add($methods['mpesa'], 'refunds', (int) $return->mobile_refund);
                    }
                    $this->add($methods['credit'], 'refunds', (int) $return->debt_reduction);
                }
            });

            foreach ($methods as &$method) {
                $method['net'] = ReportMath::add($method['sales'], -$method['refunds']);
            }
            unset($method);
            foreach ($hourly as &$bucket) {
                $bucket['net'] = ReportMath::add($bucket['sales'], -$bucket['refunds']);
            }
            unset($bucket);

            return [
                'period' => ['from' => $period->from, 'to' => $period->to, 'timezone' => 'Africa/Dar_es_Salaam'],
                'generated_at' => now()->toISOString(),
                'basis' => 'event_date_accrual',
                'payment_methods' => array_values($methods),
                'hourly' => array_values(array_filter($hourly, fn ($bucket) => $bucket['sales'] > 0 || $bucket['refunds'] > 0)),
                'top_sellers' => $this->rankSellers($products, $filters['per_page'] ?? 10),
                'slow_movers' => $this->slowMovers($soldIds, $filters['per_page'] ?? 10),
            ];
        });
    }

    private function rankSellers(array $products, int $perPage): array
    {
        $rows = [];
        foreach ($products as $productId => $entry) {
                if ($entry['sales_count'] === 0 && $entry['revenue'] === 0 && $entry['qty_atoms'] === 0) {
                continue;
            }
            $product = Product::withTrashed()->find($productId);
            if (! $product) {
                continue;
            }
            $rows[] = [
                'product_id' => $product->publicId(), 'name' => $product->name, 'unit' => $product->unit,
                'qty' => Quantity::format($entry['qty_atoms'], $product->quantity_type === 'weight'),
                'sales_count' => $entry['sales_count'],
                'revenue' => $entry['revenue'], 'cost' => $entry['cost'],
                'vat_amount' => $entry['vat'],
                'net_revenue' => ReportMath::add($entry['revenue'], -$entry['vat']),
                'profit' => ReportMath::add(ReportMath::add($entry['revenue'], -$entry['vat']), -$entry['cost']),
            ];
        }
        usort($rows, fn ($a, $b) => [$b['revenue'], $a['name']] <=> [$a['revenue'], $b['name']]);

        return array_slice($rows, 0, max(1, min(50, $perPage)));
    }

    /** Stock on hand that recorded no sale in this window, most valuable first. */
    private function slowMovers(array $sold, int $perPage): array
    {
        $rows = [];
        ProductBatch::with('product')->where('qty_remaining_atoms', '>', 0)->chunkById(100, function ($batches) use (&$rows, $sold) {
            foreach ($batches as $batch) {
                $product = $batch->product;
                if (! $product->is_active || $product->trashed() || isset($sold[$product->id])) {
                    continue;
                }
                $rows[$product->id] ??= [
                    'product_id' => $product->publicId(), 'name' => $product->name, 'unit' => $product->unit,
                    'stock_qty' => '0', 'stock_value' => 0, 'expiry_date' => null,
                ];
                $weighted = $product->quantity_type === 'weight';
                $rows[$product->id]['stock_qty'] = Quantity::format(
                    ExactMath::add(Quantity::parse($rows[$product->id]['stock_qty'], $weighted), $batch->qty_remaining_atoms),
                    $weighted,
                );
                $rows[$product->id]['stock_value'] = ReportMath::add(
                    $rows[$product->id]['stock_value'],
                    Money::lineTotal($batch->qty_remaining_atoms, $batch->cost_price, $weighted),
                );
                if ($batch->expiry_date && ($rows[$product->id]['expiry_date'] === null || $batch->expiry_date->format('Y-m-d') < $rows[$product->id]['expiry_date'])) {
                    $rows[$product->id]['expiry_date'] = $batch->expiry_date->format('Y-m-d');
                }
            }
        });
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => [$b['stock_value'], $a['name']] <=> [$a['stock_value'], $b['name']]);

        return array_slice($rows, 0, max(1, min(50, $perPage)));
    }

    private function window(Builder $query, ReportPeriod $period): Builder
    {
        if ($query->getModel() instanceof Sale) {
            return $query->where(fn ($inner) => $inner
                ->where(fn ($a) => $a->where('occurred_at', '>=', $period->start)->where('occurred_at', '<', $period->until))
                ->orWhere(fn ($a) => $a->whereNull('occurred_at')->where('created_at', '>=', $period->start)->where('created_at', '<', $period->until)));
        }

        return $query->where('created_at', '>=', $period->start)->where('created_at', '<', $period->until);
    }

    private function bounded(Builder $query): void
    {
        if ((clone $query)->limit(10001)->get(['id'])->count() > 10000) {
            throw new ApiException('REPORT_TOO_LARGE', 'Choose a narrower report period.', 422);
        }
    }

    private function add(array &$values, string|int $key, int $amount): void
    {
        $values[$key] = ReportMath::add((int) ($values[$key] ?? 0), $amount);
    }

    private function emptyProduct(): array
    {
        return ['qty_atoms' => 0, 'revenue' => 0, 'vat' => 0, 'cost' => 0, 'sales_count' => 0];
    }

    private function cost(SaleItem $item, bool $weighted): int
    {
        $price = $item->cost_price ?? $item->batch?->cost_price;
        if ($price === null) { throw new ApiException('COST_UNAVAILABLE', 'A recorded sale cost is unavailable.', 422); }
        return Money::lineTotal($item->qty_atoms, $price, $weighted);
    }
}
