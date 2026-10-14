<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\ProductImageService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

class CleanupProductImages extends Command
{
    protected $signature = 'products:cleanup-images';
    protected $description = 'Retry tenant-scoped deletion of replaced or removed private product images';

    public function handle(TenantContext $context, ProductImageService $images): int
    {
        if (! $images->capabilities()['configured']) { $this->info('Product image storage is unconfigured; no cleanup attempted.'); return self::SUCCESS; }
        Business::where('is_active', true)->orderBy('id')->chunkById(50, function ($businesses) use ($context, $images) {
            foreach ($businesses as $business) { $context->run($business, fn () => $images->retryCleanups()); }
        });
        return self::SUCCESS;
    }
}
