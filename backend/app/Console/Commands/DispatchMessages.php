<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\{Business,MessageDelivery};
use App\Services\MessagingService;
use App\Tenancy\TenantContext;
class DispatchMessages extends Command {
    protected $signature='messaging:dispatch';protected $description='Generate consented owner notices and dispatch tenant-scoped messages without retrying uncertain sends.';
    public function handle():int {
        foreach(Business::where('is_active',true)->orderBy('id')->lazyById(100) as $shop)app(TenantContext::class)->run($shop,function(){ $service=app(MessagingService::class);$service->stale();if(!app(\App\Services\PlanFeatureService::class)->features()['messaging'])return;$service->ownerNotices();foreach(MessageDelivery::whereIn('status',['queued','blocked'])->orderBy('id')->limit(100)->pluck('id') as $id)$service->dispatchOne($id);});
        $this->info('Delivery journals processed. Provider acceptance is not proof of delivery.');return self::SUCCESS;
    }
}
