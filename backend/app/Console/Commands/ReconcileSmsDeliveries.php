<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\Business;
use App\Services\SmsDeliveryReportService;
use App\Tenancy\TenantContext;
class ReconcileSmsDeliveries extends Command {
    protected $signature='messaging:reconcile-sms';
    protected $description='Check authenticated Beem delivery reports for accepted SMS without resending messages.';
    public function handle():int {
        foreach(Business::where('is_active',true)->orderBy('id')->lazyById(100) as $shop)
            app(TenantContext::class)->run($shop,fn()=>app(SmsDeliveryReportService::class)->reconcileTenant());
        $this->info('Accepted SMS delivery reports checked. No messages resent.');return self::SUCCESS;
    }
}
