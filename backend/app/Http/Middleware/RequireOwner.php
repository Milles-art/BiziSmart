<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasRole('owner')) { throw new AuthorizationException; }
        return $next($request);
    }
}
