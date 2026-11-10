<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\SaveBusinessSettingsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\BusinessSettingsService;
use Illuminate\Http\JsonResponse;

class BusinessSettingsController
{
    public function __construct(private BusinessSettingsService $settings) {}
    public function show(): JsonResponse { return ApiResponse::success($this->settings->read()); }
    public function update(SaveBusinessSettingsRequest $request): JsonResponse
    {
        return ApiResponse::success($this->settings->save($request->user(), $request->validated()));
    }
}
