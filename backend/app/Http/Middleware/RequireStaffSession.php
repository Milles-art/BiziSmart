<?php

namespace App\Http\Middleware;

use App\Services\FinancialActorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStaffSession
{
    public function handle(Request $request, Closure $next): Response
    {
        app(FinancialActorService::class)->validate($request->user());
        return $next($request);
    }
}
