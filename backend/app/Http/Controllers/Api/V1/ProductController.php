<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Products\ListProductsRequest;
use App\Http\Requests\Products\SaveProductRequest;
use App\Http\Resources\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Models\Department;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController
{
    public function __construct(private ProductService $products, private \App\Services\StockService $stock) {}

    public function index(ListProductsRequest $request): JsonResponse
    {
        $input = $request->validated();
        $query = Product::with(['activePackagings.product', 'department']);
        if (isset($input['department_id'])) {
            $department = (new Department)->resolveRouteBindingQuery(Department::query(), $input['department_id'])->firstOrFail();
            $query->where('department_id', $department->id);
        }
        // Catalogue managers can also inspect disabled items; selling staff see active products only.
        if (! $request->user()->can('manage_products')) { $query->where('is_active', true); }
        if (isset($input['search'])) { $query->where('name', 'like', '%'.addcslashes($input['search'], '\\%_').'%'); }
        if (isset($input['category'])) { $query->where('category', $input['category']); }
        if (isset($input['barcode'])) {
            $query->whereHas('barcodes', function ($codes) use ($input) {
                $codes->where('barcode', $input['barcode'])->where(function ($owner) {
                    $owner->where(function ($base) { $base->whereNull('packaging_id')->whereColumn('product_barcodes.barcode', 'products.barcode'); })
                        ->orWhereHas('packaging', fn ($pack) => $pack->whereNull('archived_at')->where('is_active', true));
                });
            });
        }
        $query = $this->stock->catalogue($query, $input);
        $lowCount = $request->user()->can('receive_stock') ? $this->stock->lowStock(clone $query)->count() : null;
        $products = $query->orderBy('name')->orderBy('id')->paginate($input['per_page'] ?? 50);
        foreach ($products as $product) {
            $product->setRelation('selectedPackaging', isset($input['barcode']) ? $product->activePackagings->firstWhere('barcode', $input['barcode']) : null);
        }
        return ApiResponse::success(['products' => ProductResource::collection($products->items())->resolve()], meta: [
            'page' => $products->currentPage(), 'per_page' => $products->perPage(), 'total' => $products->total(),
            ...($lowCount === null ? [] : ['low_stock_matches' => $lowCount]),
        ]);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        abort_if(! $product->is_active && ! $request->user()->can('manage_products'), 404);
        return ApiResponse::success((new ProductResource($product->load(['activePackagings.product', 'department'])))->resolve());
    }

    public function store(SaveProductRequest $request): JsonResponse
    {
        return ApiResponse::success((new ProductResource($this->products->save($request->user(), $request->validated())->load(['activePackagings.product', 'department'])))->resolve(), 201);
    }

    public function update(SaveProductRequest $request, Product $product): JsonResponse
    {
        return ApiResponse::success((new ProductResource($this->products->save($request->user(), $request->validated(), $product)->load(['activePackagings.product', 'department'])))->resolve());
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->products->archive($request->user(), $product);
        return ApiResponse::success(['completed' => true]);
    }
}
