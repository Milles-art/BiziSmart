<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Products\SavePackagingRequest;
use App\Http\Resources\PackagingResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Models\ProductPackaging;
use App\Services\PackagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackagingController
{
    public function __construct(private PackagingService $packagings) {}
    public function index(Request $request, Product $product): JsonResponse
    {
        $manage = $request->user()->can('manage_products');
        abort_if(! $product->is_active && ! $manage, 404);
        $query = ProductPackaging::where('product_id', $product->id)->whereNull('archived_at');
        if (! $manage) { $query->where('is_active', true); }
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $rows = $query->orderBy('name')->orderBy('id')->paginate(50, ['*'], 'page', $input['page'] ?? 1);
        foreach ($rows as $row) { $row->setRelation('product', $product); }
        return ApiResponse::success(['packagings' => PackagingResource::collection($rows->items())->resolve()], meta: [
            'page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]);
    }
    public function store(SavePackagingRequest $request, Product $product): JsonResponse
    {
        return ApiResponse::success((new PackagingResource($this->packagings->save($request->user(), $product, $request->validated())))->resolve(), 201);
    }
    public function update(SavePackagingRequest $request, Product $product, ProductPackaging $packaging): JsonResponse
    {
        return ApiResponse::success((new PackagingResource($this->packagings->save($request->user(), $product, $request->validated(), $packaging)))->resolve());
    }
    public function destroy(Request $request, Product $product, ProductPackaging $packaging): JsonResponse
    {
        $this->packagings->archive($request->user(), $product, $packaging);
        return ApiResponse::success(['completed' => true]);
    }
}
