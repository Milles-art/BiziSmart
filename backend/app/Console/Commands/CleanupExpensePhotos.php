<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\ExpenseAttachmentService;
use App\Services\ProductImageService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

class CleanupExpensePhotos extends Command
{
    protected $signature = 'expenses:cleanup-photos';
    protected $description = 'Retry tenant-scoped deletion of removed or abandoned private expense photos';

    public function handle(TenantContext $context, ProductImageService $images, ExpenseAttachmentService $photos): int
    {
        if (!$images->capabilities()['configured']) {
            $this->info('Private image storage is unconfigured; no cleanup attempted.');
            return self::SUCCESS;
        }
        Business::orderBy('id')->chunkById(50, function ($businesses) use ($context, $photos) {
            foreach ($businesses as $business) {
                $context->run($business, fn () => $photos->cleanup());
            }
        });
        return self::SUCCESS;
    }
}
