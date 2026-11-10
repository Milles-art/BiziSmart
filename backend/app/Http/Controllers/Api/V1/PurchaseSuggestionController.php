<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Suppliers\PurchaseSuggestionRequest;
use App\Http\Responses\ApiResponse;
use App\Services\PurchaseSuggestionService;

class PurchaseSuggestionController
{
    public function __invoke(PurchaseSuggestionRequest $request, string $supplier, PurchaseSuggestionService $service)
    {
        return ApiResponse::success($service->suggestions($request->user(), $supplier, $request->validated()));
    }
}
