<?php
namespace App\Http\Controllers\Api\V1;
use App\Models\Product;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
class CatalogueExportController
{
    public function __invoke(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('manage_products'), 403);
        $costs = $request->user()->can('view_buying_price');
        // Streaming executes after middleware. Capture and restore this exact tenant explicitly.
        $business = \App\Models\Business::whereKey(app(\App\Tenancy\TenantContext::class)->requireId())->firstOrFail();
        return response()->streamDownload(function () use ($costs, $business) {
            app(\App\Tenancy\TenantContext::class)->run($business, function () use ($costs) {
                $out = fopen('php://output', 'wb'); fwrite($out, "\xEF\xBB\xBF");
                $write = function (array $row) use ($out) {
                    $cells = array_map(function ($value) { $text = $value === null ? '' : (string) $value; return preg_match('/^\s*[=+\-@]/u', $text) ? "'".$text : $text; }, $row);
                    fputcsv($out, $cells, ',', '"', '');
                };
                $write(['product_id', 'product_name', 'quantity_type', 'unit', 'barcode', 'packaging_id', 'packaging_name', 'packaging_version', 'base_qty', 'selling_price', ...($costs ? ['cost_price'] : []), 'tracks_expiry', 'is_active']);
                Product::with('activePackagings')->orderBy('id')->chunkById(100, function ($products) use ($write, $costs) {
                    foreach ($products as $product) {
                        $base = [$product->publicId(), $product->name, $product->quantity_type, $product->unit];
                        $write([...$base, $product->barcode, '', '', '', '1', $product->selling_price, ...($costs ? [$product->buying_price] : []), $product->tracks_expiry ? '1' : '0', $product->is_active ? '1' : '0']);
                        foreach ($product->activePackagings as $pack) {
                            $write([...$base, $pack->barcode, $pack->publicId(), $pack->name, $pack->version, Quantity::format($pack->conversion_atoms, $product->quantity_type === 'weight'), $pack->selling_price, ...($costs ? [$product->buying_price] : []), $product->tracks_expiry ? '1' : '0', $pack->is_active ? '1' : '0']);
                        }
                    }
                }); fclose($out);
            });
        }, 'bizismart-catalogue.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
