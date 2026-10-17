<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;

class SubscriptionController
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function status(): JsonResponse
    {
        return ApiResponse::success($this->subscriptions->status());
    }
}
