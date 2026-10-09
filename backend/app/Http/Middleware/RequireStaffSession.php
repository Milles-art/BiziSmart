<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStaffSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $user || ! $user->is_active || $user->must_change_pin || ! $token?->device_id
            || ! Device::whereKey($token->device_id)->where('status', 'approved')->exists()
            || $user->getRoleNames()->count() !== 1 || ($user->hasRole('owner') && ! $user->totp_confirmed_at)) {
            throw new AuthenticationException;
        }
        return $next($request);
    }
}
