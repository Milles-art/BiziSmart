<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Products\AdjustStockRequest;
use App\Http\Resources\StockMovementResource;
use App\Http\Responses\ApiResponse;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
class StockAdjustmentController
{
    public function __construct(private StockService $stock) {}
    public function preview(AdjustStockRequest $request): JsonResponse { return ApiResponse::success($this->stock->previewAdjustment($request->user(), $request->validated())); }
    public function store(AdjustStockRequest $request): JsonResponse {
        $result = $this->stock->adjust($request->user(), $request->validated());
        return ApiResponse::success(['movement' => (new StockMovementResource($result['movement']))->resolve(), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }
}
