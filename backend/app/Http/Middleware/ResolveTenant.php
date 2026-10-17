<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Business;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();
        Auth::forgetGuards();
        try {
            if ($request->routeIs('health','ready','payments.selcom.callback','messaging.whatsapp.webhook')) {
                return $next($request);
            }
            $host = strtolower($request->getHost());
            $suffix = '.'.strtolower(config('tenancy.domain'));
            if (! str_ends_with($host, $suffix)) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }
            $subdomain = substr($host, 0, -strlen($suffix));
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $subdomain)
                || in_array($subdomain, config('tenancy.reserved_subdomains'), true)) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }
            $business = Business::query()->where('subdomain', $subdomain)->where('is_active', true)->first();
            if ($business === null) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }
            $this->context->set($business);
            return app(EnforceSubscriptionFeatures::class)->handle($request,$next);
        } finally {
            $this->context->clear();
            Auth::forgetGuards();
        }
    }
}
