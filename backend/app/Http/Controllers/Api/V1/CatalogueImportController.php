<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Products\ImportStockRequest;
use App\Http\Responses\ApiResponse;
use App\Services\CatalogueImportService;
use Illuminate\Http\JsonResponse;
class CatalogueImportController
{
    public function __construct(private CatalogueImportService $imports) {}
    public function preview(ImportStockRequest $request): JsonResponse { return ApiResponse::success($this->imports->preview($request->user(), $request->validated())); }
    public function commit(ImportStockRequest $request): JsonResponse
    {
        $result = $this->imports->commit($request->user(), $request->validated());
        return ApiResponse::success($result, $result['replayed'] ? 200 : 201);
    }
}
