<?php
namespace App\Console\Commands;
use App\Models\Business;
use App\Services\PricingService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
class ReconcilePricing extends Command {
 protected $signature='pricing:reconcile';protected $description='Record due schedule activation/end history in each active business.';
 public function handle(TenantContext $context,PricingService $pricing):int{$count=0;Business::where('is_active',true)->orderBy('id')->chunkById(50,function($businesses)use($context,$pricing,&$count){foreach($businesses as$b)$count+=$context->run($b,fn()=>$pricing->recordEffectiveSchedules());});$this->info("Recorded {$count} scheduled price lifecycle events.");return self::SUCCESS;}
}
