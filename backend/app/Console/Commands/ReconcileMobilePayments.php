<?php
namespace App\Console\Commands;
use App\Models\{Business,MobilePayment};
use App\Services\MobilePaymentService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
class ReconcileMobilePayments extends Command {
    protected $signature='payments:reconcile';
    protected $description='Verify due Selcom orders without replaying payment pushes.';
    public function handle(TenantContext $context,MobilePaymentService $payments): int {
        $failures=0;$checked=0;
        Business::where('is_active',true)->orderBy('id')->chunkById(50,function($businesses)use($context,$payments,&$failures,&$checked){
            foreach($businesses as $business)$context->run($business,function()use($payments,&$failures,&$checked){
                MobilePayment::whereNotIn('status',['confirmed','allocated','manual_refunded'])->where('next_check_at','<=',now())->orderBy('id')->chunkById(50,function($rows)use($payments,&$failures,&$checked){
                    foreach($rows as $p){try{$payments->reconcile($p->id);$checked++;}catch(\App\Exceptions\ApiException){$failures++;}}
                });
            });
        });
        $this->info("Checked {$checked} orders; {$failures} require configuration or owner review.");
        return $failures?self::FAILURE:self::SUCCESS;
    }
}
