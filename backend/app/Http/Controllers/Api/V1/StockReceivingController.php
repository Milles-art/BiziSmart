<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Products\{ListBatchesRequest, ReceiveStockRequest};
use App\Http\Resources\BatchResource;
use App\Http\Responses\ApiResponse;
use App\Models\{Product, ProductBatch};
use App\Services\StockService;
use App\Support\Quantity;
use Illuminate\Http\JsonResponse;

class StockReceivingController
{
    public function __construct(private StockService $stock) {}
    public function index(ListBatchesRequest $request, Product $product): JsonResponse
    {
        $input = $request->validated();
        $rows = ProductBatch::where('product_id', $product->id)->orderByDesc('received_at')->orderByDesc('id')->paginate($input['per_page'] ?? 50);
        foreach ($rows as $row) { $row->setRelation('product', $product); }
        return ApiResponse::success(['batches' => BatchResource::collection($rows->items())->resolve()], meta: ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]);
    }
    public function store(ReceiveStockRequest $request, Product $product): JsonResponse
    {
        $result = $this->stock->receive($request->user(), $product, $request->validated()); $movement = $result['movement'];
        return ApiResponse::success(['batch' => (new BatchResource($result['batch']))->resolve(), 'movement' => ['id' => $movement->publicId(), 'type' => $movement->type,
            'qty_change' => Quantity::format($movement->qty_change_atoms, $product->quantity_type === 'weight'), 'qty_after' => Quantity::format($movement->qty_after_atoms, $product->quantity_type === 'weight')],
            'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }
}
