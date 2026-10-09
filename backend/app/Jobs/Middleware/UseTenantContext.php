<?php

namespace App\Jobs\Middleware;

use App\Models\Business;
use App\Tenancy\TenantContext;
use Closure;
use LogicException;

class UseTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        if (! isset($job->businessId) || ! is_int($job->businessId) || $job->businessId < 1) {
            throw new LogicException('A tenant job requires a business ID.');
        }
        $business = Business::query()->where('is_active', true)->find($job->businessId);
        if ($business === null) {
            throw new LogicException('The job business is missing or inactive.');
        }
        return app(TenantContext::class)->run($business, fn () => $next($job));
    }
}
