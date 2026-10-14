<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Products\ListMovementsRequest;
use App\Http\Resources\StockMovementResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;

class StockMovementController
{
    public function __construct(private StockService $stock) {}
    public function index(ListMovementsRequest $request, Product $product): JsonResponse
    {
        $input = $request->validated();
        $rows = $this->stock->movements($product, $input)->paginate($input['per_page'] ?? 50);
        return ApiResponse::success(['movements' => StockMovementResource::collection($rows->items())->resolve()], meta: [
            'page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]);
    }
}
