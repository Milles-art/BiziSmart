<?php
namespace App\Services;
use App\Models\{Business,MessageDelivery};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class SmsDeliveryReportService {
    public function __construct(private TenantContext $context,private MessageTransport $configuration,private SmsDeliveryReportTransport $transport){}

    /** Called by a scheduler with one explicit tenant; accepted sends only, no resend authority. */
    public function reconcileTenant(int $limit=25):void {
        if(!$this->configuration->capabilities()['sms']['ready']||!app(PlanFeatureService::class)->features()['messaging'])return;
        $cutoff=now()->subMinutes(5);
        $ids=MessageDelivery::where('channel','sms')->where('status','accepted')->where('provider_id','like','beem:%')
            ->where('accepted_at','<=',$cutoff)->where('updated_at','<=',$cutoff)->orderBy('updated_at')->orderBy('id')->limit(max(1,min(100,$limit)))->pluck('id');
        foreach($ids as $id)$this->poll($id);
    }

    public function poll(int $id):void {
        $claim=DB::transaction(function()use($id){
            $shop=Business::whereKey($this->context->requireId())->where('is_active',true)->lockForUpdate()->first();
            if(!$shop||!$this->configuration->capabilities()['sms']['ready']||!app(PlanFeatureService::class)->features()['messaging'])return null;
            $row=MessageDelivery::whereKey($id)->lockForUpdate()->first();$cutoff=now()->subMinutes(5);
            if(!$row||$row->channel!=='sms'||$row->status!=='accepted'||!is_string($row->provider_id)||!str_starts_with($row->provider_id,'beem:')
                ||!$row->accepted_at||$row->accepted_at->gt($cutoff)||$row->updated_at->gt($cutoff))return null;
            $requestId=substr($row->provider_id,5);if($requestId===''||strlen($requestId)>186)return null;
            $claim=['id'=>$row->id,'provider_id'=>$row->provider_id,'recipient'=>$row->recipient,'request_id'=>$requestId];
            // A durable five-minute GET claim survives command overlap, timeouts and process crashes.
            $row->updated_at=now();$row->save();return $claim;
        },3);
        if(!$claim)return;
        $status=$this->transport->lookup($claim['request_id'],$claim['recipient']);
        if(!in_array($status,['DELIVERED','UNDELIVERED'],true))return;
        DB::transaction(function()use($claim,$status){
            $row=MessageDelivery::whereKey($claim['id'])->lockForUpdate()->first();
            if(!$row||$row->status!=='accepted'||$row->channel!=='sms'||$row->provider_id!==$claim['provider_id']||$row->recipient!==$claim['recipient'])return;
            if($status==='DELIVERED'){$row->status='delivered';$row->delivered_at=now();$row->failure_code=null;}
            else {$row->status='failed';$row->failure_code='PROVIDER_DELIVERY_FAILED';}
            $row->save();
        },3);
    }
}
