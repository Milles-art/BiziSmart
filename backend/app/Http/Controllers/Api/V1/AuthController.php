<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\ChallengeRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPinRequest;
use App\Http\Resources\StaffResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\StaffAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController
{
    public function __construct(private StaffAuthService $auth) {}
    public function login(LoginRequest $request): JsonResponse
    {
        return $this->respond($this->auth->login($request->validated(), $request->ip()));
    }
    public function supervisor(\App\Http\Requests\Auth\SupervisorCheckRequest $request, \App\Services\SupervisorApprovalService $approvals): JsonResponse
    {
        return ApiResponse::success($approvals->issue($request->user(), $request->validated()));
    }
    public function setup(\App\Http\Requests\Auth\OwnerSetupRequest $request, \App\Services\OwnerSetupService $setup): JsonResponse
    {
        $setup->register($request->validated());
        return $this->respond($this->auth->login($request->validated(), $request->ip()));
    }
    public function verify(ChallengeRequest $request): JsonResponse
    {
        return $this->respond($this->auth->verifyTotp($request->validated('challenge_token'), $request->validated('code')));
    }
    public function changePin(ChallengeRequest $request): JsonResponse
    {
        return $this->respond($this->auth->changePin($request->validated('challenge_token'), $request->validated('new_pin')));
    }
    public function resetPin(ResetPinRequest $request): JsonResponse
    {
        $target = (new User)->resolveRouteBinding($request->validated('user_id')) ?? abort(404);
        $this->auth->resetPin($request->user(), $target, $request->validated('new_pin'));
        return ApiResponse::success(['completed' => true]);
    }
    public function logout(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            $user=$request->user();
            $user->refreshForUpdate();
            $token=$user->currentAccessToken();
            \App\Models\OfflineLease::where('user_id',$user->id)->where('device_id',$token->device_id)
                ->whereNull('revoked_at')->update(['revoked_at'=>now()]);
            $token->delete();
        },3);
        return ApiResponse::success(['completed' => true]);
    }
    public function rotate(Request $request): JsonResponse { return $this->respond($this->auth->rotate($request->user())); }
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $business = $user->business;
        return ApiResponse::success(['user' => (new StaffResource($user))->resolve(), 'features'=>app(\App\Services\PlanFeatureService::class)->features(), 'business' => ['name' => $business->name,
            'vat_rate' => $business->vat_rate, 'settings' => array_intersect_key($business->settings ?? [], ['till_variance_limit' => true])]]);
    }
    public function features(Request $request): JsonResponse
    {
        return ApiResponse::success(['features' => (object) app(\App\Services\PlanFeatureService::class)->features()]);
    }
    private function respond(array $result): JsonResponse { return ApiResponse::success($result['data'], $result['status']); }
}
