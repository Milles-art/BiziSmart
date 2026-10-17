<?php

namespace App\Console\Commands;

use App\Models\{Business, MessageDelivery};
use App\Services\{ExpenseAttachmentService, LossAlertService, MessagingService, PlanFeatureService, PricingService, ProductImageService, SmsDeliveryReportService};
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

class SharedHostingUnit extends Command
{
    public const TASKS = ['pricing', 'alerts', 'notices', 'send', 'sms', 'product-images', 'expense-photos'];
    protected $signature = 'bizismart:shared-unit {business} {task}';
    protected $description = 'Internal finite tenant work unit for the shared-hosting cron runner';
    protected $hidden = true;

    public function handle(TenantContext $context): int
    {
        $task = $this->argument('task');
        if (config('hosting.profile') !== 'shared' || !in_array($task, self::TASKS, true)
            || !ctype_digit((string) $this->argument('business'))) {
            return self::FAILURE;
        }
        $shop = Business::whereKey($this->argument('business'))->where('is_active', true)->first();
        if (!$shop) return self::SUCCESS;
        $context->run($shop, function () use ($task) {
            if ($task === 'pricing') app(PricingService::class)->recordEffectiveSchedules();
            if ($task === 'alerts') {
                // Revisit yesterday as well, so the last five-minute window is not lost at midnight.
                foreach ([now('Africa/Dar_es_Salaam')->subDay()->toDateString(), now('Africa/Dar_es_Salaam')->toDateString()] as $date) {
                    app(LossAlertService::class)->evaluate($date);
                }
            }
            if (in_array($task, ['notices', 'send', 'sms'], true)) {
                $messages = app(MessagingService::class);
                $messages->stale();
                if (!app(PlanFeatureService::class)->features()['messaging']) return;
                if ($task === 'notices') $messages->ownerNotices();
                if ($task === 'send') {
                    // Retry ordering lets a blocked row be revisited without starving later queued rows.
                    $attempted = []; $started = hrtime(true);
                    while (count($attempted) < 10 && (hrtime(true) - $started) / 1e9 < 20) {
                        $id = MessageDelivery::whereIn('status', ['queued', 'blocked'])->whereNotIn('id', $attempted)
                            ->orderBy('updated_at')->orderBy('id')->value('id');
                        if (!$id) break;
                        $attempted[] = $id;
                        $messages->dispatchOne($id);
                    }
                }
                if ($task === 'sms') app(SmsDeliveryReportService::class)->reconcileTenant(1);
            }
            if (in_array($task, ['product-images', 'expense-photos'], true) && app(ProductImageService::class)->capabilities()['configured']) {
                if ($task === 'product-images') app(ProductImageService::class)->retryCleanups();
                else app(ExpenseAttachmentService::class)->cleanup();
            }
        });
        return self::SUCCESS;
    }
}
