<?php
namespace App\Services;
use App\Models\{Activity,Business,LossAlert,SaleReturn,User};
use App\Support\{ReportMath,ReportPeriod};
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
class LossAlertService {
    public function __construct(private TenantContext $context,private FinancialActorService $actors){}
    public function configuration():array{return ['refund_count'=>null,'refund_amount'=>null,'void_count'=>null,...(Business::findOrFail($this->context->requireId())->settings['loss_alerts']??[])];}
    public function configure(User $original,array $input):array{return DB::transaction(function()use($original,$input){$actor=$this->owner($original);$shop=Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();$settings=$shop->settings??[];$settings['loss_alerts']=['refund_count'=>isset($input['refund_count'])?(int)$input['refund_count']:null,'refund_amount'=>isset($input['refund_amount'])?(int)$input['refund_amount']:null,'void_count'=>isset($input['void_count'])?(int)$input['void_count']:null];$shop->settings=$settings;$shop->save();activity('loss_prevention')->performedOn($actor)->causedBy($actor)->event('loss_alert_thresholds_changed')->withProperties(['attributes'=>$settings['loss_alerts']])->log('Owner configured suspicious activity thresholds');return $settings['loss_alerts'];},3);}
    /** Runs in a set tenant, from authenticated owner refresh or the tenant-aware scheduled command. */
    public function evaluate(string $date):int{return DB::transaction(function()use($date){
        Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();$config=$this->configuration();$period=new ReportPeriod($date,$date);$stats=[];
        if($config['refund_count']!==null||$config['refund_amount']!==null)SaleReturn::where('created_at','>=',$period->start)->where('created_at','<',$period->until)->chunkById(100,function($rows)use(&$stats){foreach($rows as $record){$stats[$record->user_id]??=['refund_count'=>0,'refund_amount'=>0,'void_count'=>0];++$stats[$record->user_id]['refund_count'];$stats[$record->user_id]['refund_amount']=ReportMath::add($stats[$record->user_id]['refund_amount'],$record->refund_total);}});
        // Completed receipt voids retain their approved return journal and emit one explicit sale_voided event. Ordinary refunds do not become voids.
        if($config['void_count']!==null)Activity::where('event','sale_voided')->where('created_at','>=',$period->start)->where('created_at','<',$period->until)->chunkById(100,function($rows)use(&$stats){foreach($rows as $record){if(!$record->causer_id||$record->causer_type!==(new User)->getMorphClass())continue;$stats[$record->causer_id]??=['refund_count'=>0,'refund_amount'=>0,'void_count'=>0];++$stats[$record->causer_id]['void_count'];}});
        $created=0;foreach($stats as $user=>$values)foreach($config as $kind=>$threshold){if($threshold===null||$values[$kind]<$threshold)continue;if(!User::withTrashed()->whereKey($user)->exists())continue;$alert=LossAlert::firstOrCreate(['user_id'=>$user,'kind'=>$kind,'event_date'=>$date],['evidence'=>['observed'=>$values[$kind],'threshold'=>$threshold,'timezone'=>'Africa/Dar_es_Salaam']]);if($alert->wasRecentlyCreated)++$created;}
        return $created;
    },3);}
    public function refresh(User $original):array{return DB::transaction(function()use($original){$this->owner($original);return ['created'=>$this->evaluate(now('Africa/Dar_es_Salaam')->toDateString())];},3);}
    public function listing(int $page=1):array{$rows=LossAlert::with('staff')->orderByDesc('event_date')->orderByDesc('id')->paginate(50,['*'],'page',$page);return ['alerts'=>array_map(fn($a)=>['id'=>$a->publicId(),'staff_id'=>$a->staff->publicId(),'staff_name'=>$a->staff->name,'kind'=>$a->kind,'event_date'=>$a->event_date,'evidence'=>$a->evidence,'acknowledged_at'=>$a->acknowledged_at?->toISOString()],$rows->items()),'meta'=>['page'=>$rows->currentPage(),'per_page'=>$rows->perPage(),'total'=>$rows->total()],'configuration'=>$this->configuration()];}
    public function acknowledge(User $original,LossAlert $alert):void{DB::transaction(function()use($original,$alert){$actor=$this->owner($original);$alert->refreshForUpdate();if(!$alert->acknowledged_at){$alert->acknowledged_at=now();$alert->acknowledged_by=$actor->id;$alert->save();activity('loss_prevention')->performedOn($alert)->causedBy($actor)->event('loss_alert_acknowledged')->log('Owner acknowledged activity alert');}},3);}
    private function owner(User $original):User{$actor=$this->actors->lock($original,'view_reports');if(!$actor->hasRole('owner'))throw new AuthorizationException;return $actor;}
}


