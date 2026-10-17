<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\EnterpriseTokenRequest;
use App\Http\Responses\ApiResponse;
use App\Models\PersonalAccessToken;
use App\Services\EnterpriseService;
use Illuminate\Http\JsonResponse;
class EnterpriseController {
    public function __construct(private EnterpriseService $enterprise) {}
    public function index(): JsonResponse { return ApiResponse::success($this->enterprise->index(request()->user())); }
    public function store(EnterpriseTokenRequest $r): JsonResponse { return ApiResponse::success($this->enterprise->issue($r->user(), $r->validated()), 201); }
    public function destroy(PersonalAccessToken $token): JsonResponse { return ApiResponse::success($this->enterprise->revoke(request()->user(), $token)); }
}
