<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Products\ListPriceHistoryRequest;
use App\Http\Resources\ProductPriceHistoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\{Product, ProductPriceHistory};
use App\Support\Quantity;
use Illuminate\Http\{JsonResponse, Request};
class ProductPriceController
{
    public function history(ListPriceHistoryRequest $request, Product $product): JsonResponse
    {
        $records = ProductPriceHistory::with(['actor', 'packaging'])->where('product_id', $product->id)->orderByDesc('id')->paginate($request->validated('per_page') ?? 50);
        return ApiResponse::success(['prices' => ProductPriceHistoryResource::collection($records->items())->resolve()], meta: ['page' => $records->currentPage(), 'per_page' => $records->perPage(), 'total' => $records->total()]);
    }
    public function labels(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->can('manage_products'), 403);
        $product->load('activePackagings');
        $labels = [['id' => $product->publicId(), 'product_id' => $product->publicId(), 'product_name' => $product->name,
            'packaging_name' => null, 'packaging_version' => null, 'base_qty' => '1', 'unit' => $product->unit,
            'barcode' => $product->barcode, 'selling_price' => app(\App\Services\PricingService::class)->effectivePrice($product)]];
        foreach ($product->activePackagings as $pack) {
            $labels[] = ['id' => $pack->publicId(), 'product_id' => $product->publicId(), 'product_name' => $product->name,
                'packaging_name' => $pack->name, 'packaging_version' => $pack->version,
                'base_qty' => Quantity::format($pack->conversion_atoms, $product->quantity_type === 'weight'), 'unit' => $product->unit,
                'barcode' => $pack->barcode, 'selling_price' => app(\App\Services\PricingService::class)->effectivePrice($product,$pack)];
        }
        return ApiResponse::success(['labels' => $labels, 'generated_at' => now()->toIso8601String()]);
    }
}
