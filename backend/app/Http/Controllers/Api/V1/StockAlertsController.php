<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Products\StockAlertsRequest;
use App\Http\Resources\{ExpiryAlertResource, ProductResource};
use App\Http\Responses\ApiResponse;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;

class StockAlertsController
{
    public function __construct(private StockService $stock) {}
    public function reorder(StockAlertsRequest $request): JsonResponse
    {
        $input = $request->validated();
        $rows = $this->stock->reorderAlerts()->paginate($input['per_page'] ?? 50);
        return ApiResponse::success(['products' => ProductResource::collection($rows->items())->resolve()], meta: $this->meta($rows));
    }
    public function expiry(StockAlertsRequest $request): JsonResponse
    {
        $input = $request->validated();
        $rows = $this->stock->expiryAlerts((int) ($input['days'] ?? 30))->paginate($input['per_page'] ?? 50);
        return ApiResponse::success(['batches' => ExpiryAlertResource::collection($rows->items())->resolve()], meta: $this->meta($rows));
    }
    private function meta($rows): array { return ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]; }
}
