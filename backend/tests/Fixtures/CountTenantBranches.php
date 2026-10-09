<?php

namespace Tests\Fixtures;

use App\Jobs\TenantJob;
use App\Models\Branch;
use App\Tenancy\TenantCache;

class CountTenantBranches extends TenantJob
{
    public function handle(TenantCache $cache): void
    {
        $cache->put('branch-count', Branch::count(), 60);
    }
}
