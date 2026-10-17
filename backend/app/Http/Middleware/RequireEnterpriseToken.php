<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headless enterprise integrations. Requires an explicitly issued
 * enterprise:read token ability; interactive staff session tokens carry '*'
 * and are rejected here even though Sanctum's tokenCan would accept them.
 * Tenant binding is enforced by Sanctum's token authentication callback.
 */
class RequireEnterpriseToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user=$request->user();$token=$user?->currentAccessToken();
        if (! $token || array_values((array) ($token->abilities ?? [])) !== ['enterprise:read'] || $token->device_id !== null) {
            throw new AuthorizationException;
        }
        if(!$user->is_active||$user->must_change_pin||!$user->ownerAuthenticationReady()||$user->getRoleNames()->count()!==1||!$token->expires_at||!$token->expires_at->isFuture())throw new AuthenticationException;
        if(!$user->hasRole('owner'))throw new AuthorizationException;
        return $next($request);
    }
}
