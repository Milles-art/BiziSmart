<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{Business, Product, Supplier, SupplierInvoice, User};
use App\Support\Quantity;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Read-only purchasing evidence. SupplierService remains the only order writer. */
class PurchaseSuggestionService
{
    public function __construct(private FinancialActorService $actors, private StockService $stock, private TenantContext $context) {}

    public function suggestions(User $original, string $supplierId, array $filters): array
    {
        return DB::transaction(function () use ($original, $supplierId, $filters) {
            $actor = $this->actors->lock($original, 'manage_products');
            if (! $actor->hasRole('owner')) throw new AuthorizationException;
            Business::whereKey($this->context->requireId())->where('is_active', true)->lockForUpdate()->firstOrFail();
            $supplier = (new Supplier)->resolveRouteBindingQuery(Supplier::query(), $supplierId)->first();
            if (! $supplier) throw new ApiException('NOT_FOUND', 'Supplier not found.', 404);
            if (! $supplier->is_active) throw new ApiException('SUPPLIER_INACTIVE', 'Activate this supplier before preparing an order.', 409);

            // Actual deliveries establish supplier/product history. A draft order is not evidence.
            $history = [];
            foreach (SupplierInvoice::where('supplier_id', $supplier->id)->orderByDesc('invoice_date')->orderByDesc('id')->cursor() as $invoice) {
                foreach ($invoice->lines as $line) {
                    $id = $line['product_id'];
                    if (! isset($history[$id])) $history[$id] = [
                        'cost_price' => $line['cost_price'], 'invoice_id' => $invoice->publicId(),
                        'invoice_date' => $invoice->invoice_date->toDateString(),
                    ];
                }
            }
            $productIds = array_map(fn (string $id) => substr($id, strlen(Product::PUBLIC_ID_PREFIX)), array_keys($history));
            $page = $this->stock->reorderAlerts()->whereIn('public_id', $productIds)
                ->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);
            $lines = [];
            foreach ($page->items() as $product) {
                $weighted = $product->quantity_type === 'weight';
                $available = $this->stock->checkedAtoms($product->stock_atoms);
                // Eligible balances are nonnegative. This is a threshold gap, not a sales forecast.
                $gap = $product->reorder_level_atoms - $available;
                $lines[] = [
                    'product_id' => $product->publicId(), 'name' => $product->name,
                    'quantity_type' => $product->quantity_type, 'unit' => $product->unit,
                    'stock' => Quantity::format($available, $weighted),
                    'reorder_level' => Quantity::format($product->reorder_level_atoms, $weighted),
                    'shortage_to_reorder' => Quantity::format($gap, $weighted),
                    'suggested_qty' => $gap > 0 ? Quantity::format($gap, $weighted) : null,
                    'packaging_id' => null, 'historical_cost' => $history[$product->publicId()],
                ];
            }
            return ['supplier' => ['id' => $supplier->publicId(), 'name' => $supplier->name], 'lines' => $lines,
                'basis' => 'received_supplier_history_and_reorder_threshold',
                'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]];
        }, 3);
    }
}
