<?php
namespace App\Console\Commands;
use App\Models\Business;
use App\Services\LossAlertService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
class EvaluateLossAlerts extends Command {
    protected $signature='bizismart:loss-alerts {--date= : Tanzania event date (default today)}';
    protected $description='Evaluate owner-configured suspicious activity thresholds in each active business';
    public function handle(TenantContext $context,LossAlertService $service):int{$date=$this->option('date')?:now('Africa/Dar_es_Salaam')->toDateString();new \App\Support\ReportPeriod($date,$date);$created=0;Business::where('is_active',true)->chunkById(100,function($businesses)use($context,$service,$date,&$created){foreach($businesses as $business)$created+=$context->run($business,fn()=>$service->evaluate($date));});$this->info('Created '.$created.' activity alerts.');return self::SUCCESS;}
}
