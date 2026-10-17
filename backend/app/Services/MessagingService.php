<?php
namespace App\Services;
use App\Models\{Business,Customer,CustomerMessageConsent,MessageDelivery,MessagingOperation,User,Sale,NotificationPreference,StaffNotification,Subscription};
use App\Tenancy\TenantContext;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
class MessagingService {
    public function __construct(private TenantContext $context,private FinancialActorService $actors,private MessageTransport $transport){}
    public function overview(User $actor):array {
        if(!$actor->hasRole('owner'))throw new \Illuminate\Auth\Access\AuthorizationException;
        $rows=MessageDelivery::latest('id')->paginate(50);return ['channels'=>$this->transport->capabilities(),'automation'=>$this->automation(),'deliveries'=>array_map(fn($r)=>$this->resource($r),$rows->items()),'meta'=>['page'=>$rows->currentPage(),'total'=>$rows->total()]];
    }
    public function consent(Customer $customer):array {$row=CustomerMessageConsent::where('customer_id',$customer->id)->first();return ['whatsapp'=>$row?->whatsapp??false,'sms'=>$row?->sms??false,'receipts'=>$row?->receipts??false,'debt_reminders'=>$row?->debt_reminders??false,'revision'=>$row?->revision??0];}
    public function commit(User $original,string $action,array $input,string $key):array {
        return DB::transaction(function()use($original,$action,$input,$key){$actor=$this->actors->lock($original,'manage_customers');Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();$fingerprint=SupervisorApprovalService::snapshotHash(['action'=>$action,'input'=>$input]);$existing=MessagingOperation::where('operation_id',strtolower($key))->lockForUpdate()->first();if($existing){if($existing->actor_id!==$actor->id||$existing->device_id!==$actor->currentAccessToken()->device_id||!hash_equals($existing->fingerprint,$fingerprint))throw new ApiException('OPERATION_ID_REUSED','This messaging reference has different details.',409);return [...$existing->result,'replayed'=>true];}
            $customer=$action==='automation'?null:(new Customer)->resolveRouteBindingQuery(Customer::query(),$input['customer_id'])->lockForUpdate()->firstOrFail();
            if($action==='automation'){if(!$actor->hasRole('owner'))throw new \Illuminate\Auth\Access\AuthorizationException;$shop=Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();if($this->automation()['revision']!==(int)$input['expected_revision'])throw new ApiException('AUTOMATION_CHANGED','Refresh messaging automation settings.',409);$settings=$shop->settings??[];$settings['messaging_automation']=['daily_summary'=>$input['daily_summary'],'debt_reminders'=>$input['debt_reminders'],'at_time'=>$input['at_time'],'renewal_days'=>$input['renewal_days'],'revision'=>$this->automation()['revision']+1];$shop->settings=$settings;$shop->save();$result=['automation'=>$settings['messaging_automation']];}
            elseif($action==='consent'){$row=CustomerMessageConsent::where('customer_id',$customer->id)->lockForUpdate()->first();if(($row?->revision??0)!==(int)$input['expected_revision'])throw new ApiException('CONSENT_CHANGED','Refresh customer consent before saving.',409);$row??=new CustomerMessageConsent(['customer_id'=>$customer->id]);$row->fill(array_intersect_key($input,array_flip(['whatsapp','sms','receipts','debt_reminders','evidence'])));$row->recorded_by=$actor->id;$row->revision=($row->revision??0)+1;$row->save();$result=['consent'=>$this->consent($customer)];}
            elseif($action==='receipt'){$sale=(new Sale)->resolveRouteBindingQuery(Sale::query(),$input['sale_id'])->lockForUpdate()->firstOrFail();if($sale->customer_id!==$customer->id)throw new ApiException('RECEIPT_CUSTOMER_MISMATCH','This receipt belongs to another customer.',422);if(!$actor->hasRole('owner')&&($sale->branch_id!==$actor->branch_id||($actor->hasRole('cashier')&&$sale->cashier_id!==$actor->id)))throw new \Illuminate\Auth\Access\AuthorizationException;$text=$customer->business->name.' receipt '.$sale->receipt_number.'. Total TSh '.$sale->total.'. VAT included TSh '.$sale->vat_amount.'. ';foreach($sale->lines as $line)$text.=$line['name'].' '.$line['qty'].' '.($line['packaging_name']??$line['unit']).': TSh '.$line['line_total'].'; ';$result=['delivery'=>$this->resource($this->customerMessage($customer,$input['channel'],'receipts','receipt:'.$sale->publicId(),mb_substr($text,0,3000)))];}
            else {if(!$actor->hasRole('owner'))throw new \Illuminate\Auth\Access\AuthorizationException;$result=['delivery'=>$this->resource($this->customerMessage($customer,$input['channel'],'debt_reminders','debt:'.now('Africa/Dar_es_Salaam')->toDateString(),$customer->business->name.': your recorded outstanding balance is TSh '.$customer->outstanding_balance.'. Please contact the shop about payment.'))];}
            MessagingOperation::create(['operation_id'=>strtolower($key),'actor_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'fingerprint'=>$fingerprint,'result'=>$result]);return [...$result,'replayed'=>false];
        },3);
    }
    private function customerMessage(Customer $c,string $channel,string $purpose,string $event,string $text):MessageDelivery {
        $consent=$this->consent($c);if(!$consent[$channel]||!$consent[$purpose]||!$c->phone)throw new ApiException('MESSAGE_CONSENT_REQUIRED','Record the customer’s explicit channel and purpose consent first.',422);
        return $this->queue($channel,$purpose,'customer:'.$c->publicId().':'.$event,$c->phone,$text,$c->id,null);
    }
    public function automation():array{return Business::findOrFail($this->context->requireId())->settings['messaging_automation']??['daily_summary'=>false,'debt_reminders'=>false,'at_time'=>'09:00','renewal_days'=>null,'revision'=>0];}
    private function queue(string $channel,string $purpose,string $event,string $phone,string $text,?int $customer,?int $user):MessageDelivery {
        return MessageDelivery::firstOrCreate(['event_key'=>hash('sha256',$event),'channel'=>$channel],['purpose'=>$purpose,'customer_id'=>$customer,'user_id'=>$user,'recipient'=>$phone,'message'=>$text,'status'=>'queued']);
    }
    public function ownerNotices():void {
        $shop=Business::findOrFail($this->context->requireId());$automation=$this->automation();$now=now('Africa/Dar_es_Salaam');$afterTime=$now->format('H:i')>=$automation['at_time'];
        foreach(User::where('is_active',true)->whereHas('roles',fn($q)=>$q->where('name','owner'))->get() as $owner){$p=NotificationPreference::where('user_id',$owner->id)->first();foreach(['whatsapp','sms'] as $channel){if(!$p?->$channel)continue;
            foreach(StaffNotification::where('user_id',$owner->id)->where('created_at','>=',now()->subDay())->orderBy('id')->limit(100)->get() as $notice)$this->queue($channel,'owner_alert','notice:'.$notice->publicId().':'.$owner->publicId(),$owner->phone,$shop->name.': '.$notice->type.'. Open BiziSmart to review the authorised details.',null,$owner->id);
            foreach(\App\Models\LossAlert::where('created_at','>=',now()->subDay())->orderBy('id')->limit(100)->get() as $alert)$this->queue($channel,'owner_alert','loss:'.$alert->publicId().':'.$owner->publicId(),$owner->phone,$shop->name.': a configured suspicious-activity threshold was reached. Open BiziSmart to review.',null,$owner->id);
            $s=Subscription::first();if($s&&$automation['renewal_days']!==null&&$s->ends_at->between(now(),now()->addDays($automation['renewal_days'])))$this->queue($channel,'renewal','renewal:'.$s->publicId().':'.$s->ends_at->toDateString().':'.$owner->publicId(),$owner->phone,$shop->name.': your subscription ends on '.$s->ends_at->setTimezone('Africa/Dar_es_Salaam')->toDateString().'. Contact BiziSmart to renew.',null,$owner->id);
            if($automation['daily_summary']&&$afterTime){$date=$now->copy()->subDay()->toDateString();$event='summary:'.$date.':'.$owner->publicId();if(!MessageDelivery::where('event_key',hash('sha256',$event))->where('channel',$channel)->exists()){$summary=app(ReportService::class)->summary(['from'=>$date,'to'=>$date])['summary'];$this->queue($channel,'daily_summary',$event,$owner->phone,$shop->name.' '.$date.': net sales TSh '.$summary['net_sales_gross'].', sales '.$summary['sale_count'].'. Open BiziSmart for the full report.',null,$owner->id);}}
        }}
        if($automation['debt_reminders']&&$afterTime)foreach(Customer::where('outstanding_balance','>',0)->orderBy('id')->lazyById(100) as $customer){$consent=$this->consent($customer);if(!$consent['debt_reminders']||!$customer->phone)continue;foreach(['whatsapp','sms'] as $channel)if($consent[$channel])$this->customerMessage($customer,$channel,'debt_reminders','debt:'.$now->toDateString(),$shop->name.': your recorded outstanding balance is TSh '.$customer->outstanding_balance.'. Please contact the shop about payment.');}
    }
    public function dispatchOne(int $id):void {
        $row=DB::transaction(function()use($id){$r=MessageDelivery::whereKey($id)->lockForUpdate()->first();if(!$r||!in_array($r->status,['queued','blocked'],true))return null;
            if($r->created_at->lt(now()->subDay())){$r->status='cancelled';$r->failure_code='MESSAGE_EXPIRED';$r->save();return null;}
            if(!app(PlanFeatureService::class)->features()['messaging']){$r->status='blocked';$r->failure_code='FEATURE_DISABLED';$r->save();return null;}
            if(!$this->eligible($r)){$r->status='cancelled';$r->failure_code='CONSENT_REVOKED';$r->save();return null;}
            if(!$this->transport->capabilities()[$r->channel]['ready']){$r->status='blocked';$r->failure_code='PROVIDER_NOT_CONFIGURED';$r->save();return null;}
            $r->status='dispatching';$r->dispatch_started_at=now();$r->failure_code=null;$r->save();return $r;
        },3);
        if(!$row)return;
        $outcome=$this->transport->send($row);
        try{DB::transaction(function()use($row,$outcome){$fresh=MessageDelivery::whereKey($row->id)->lockForUpdate()->firstOrFail();if($fresh->status!=='dispatching')return;$fresh->fill($outcome);if($fresh->status==='accepted')$fresh->accepted_at=now();$fresh->save();app(MessageEvidenceService::class)->apply($fresh);},3);}
        catch(\Illuminate\Database\UniqueConstraintViolationException){DB::transaction(function()use($row){$fresh=MessageDelivery::whereKey($row->id)->lockForUpdate()->firstOrFail();if($fresh->status!=='dispatching')return;$fresh->status='uncertain';$fresh->failure_code='PROVIDER_ID_CONFLICT';$fresh->save();},3);}
    }
    private function eligible(MessageDelivery $r):bool {
        if(!app(SubscriptionService::class)->active())return false;
        if($r->customer_id){$c=Customer::find($r->customer_id);if(!$c||$c->phone!==$r->recipient)return false;$p=$this->consent($c);return $p[$r->channel]&&$p[$r->purpose];}
        $u=User::find($r->user_id);$p=NotificationPreference::where('user_id',$r->user_id)->first();return $u&&$u->is_active&&$u->hasRole('owner')&&$u->phone===$r->recipient&&$p?->{$r->channel};
    }
    public function stale():void {MessageDelivery::where('status','dispatching')->where('dispatch_started_at','<',now()->subMinutes(3))->update(['status'=>'uncertain','failure_code'=>'PROVIDER_OUTCOME_UNKNOWN']);}
    private function resource(MessageDelivery $r):array{return ['id'=>$r->publicId(),'channel'=>$r->channel,'purpose'=>$r->purpose,'status'=>$r->status,'failure_code'=>$r->failure_code,'accepted_at'=>$r->accepted_at?->toISOString(),'delivered_at'=>$r->delivered_at?->toISOString(),'created_at'=>$r->created_at->toISOString()];}
}
