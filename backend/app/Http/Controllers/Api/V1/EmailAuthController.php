<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\EmailAuthRequest;
use App\Http\Responses\ApiResponse;
use App\Services\EmailAuthService;
use App\Services\ShopRegistrationService;
use Illuminate\Http\JsonResponse;

class EmailAuthController
{
    public function __construct(private EmailAuthService $auth) {}
    public function login(EmailAuthRequest $request): JsonResponse
    {
        $result = $this->auth->login($request->validated(), $request->ip());
        return ApiResponse::success($result['data'], $result['status']);
    }
    public function setup(EmailAuthRequest $request): JsonResponse
    {
        $result = $this->auth->setup($request->validated(), $request->ip());
        return ApiResponse::success($result['data'], $result['status']);
    }
    public function forgot(EmailAuthRequest $request): JsonResponse
    {
        $this->auth->forgot($request->validated('email'));
        return ApiResponse::success(['message' => 'If this address can receive a password reset, a link will be sent.']);
    }
    public function registerStatus(): JsonResponse
    {
        return ApiResponse::success($this->auth->registrationAvailable(request()->getHost()));
    }
    public function register(EmailAuthRequest $request): JsonResponse
    {
        $subdomain = app(ShopRegistrationService::class)->subdomainFor($request->getHost());
        $result = $this->auth->registerShop($request->validated(), $subdomain, $request->ip());
        return ApiResponse::success($result['data'], $result['status']);
    }
    public function reset(EmailAuthRequest $request): JsonResponse
    {
        $this->auth->reset($request->validated());
        return ApiResponse::success(['completed' => true]);
    }
    public function activate(EmailAuthRequest $request): JsonResponse
    {
        $this->auth->activate($request->validated());
        return ApiResponse::success(['completed' => true]);
    }
    public function enroll(EmailAuthRequest $request): JsonResponse
    {
        $this->auth->enroll($request->user(), $request->validated());
        return ApiResponse::success(['completed' => true]);
    }
    public function activationLink(EmailAuthRequest $request): JsonResponse
    {
        return ApiResponse::success($this->auth->activationLink($request->user(), $request->validated('user_id')));
    }
}
