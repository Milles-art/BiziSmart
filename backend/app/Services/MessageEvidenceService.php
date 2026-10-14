<?php
namespace App\Services;
use App\Models\{MessageDelivery,MessageProviderEvent};
class MessageEvidenceService {
    /** Caller holds the delivery row lock. Early authenticated callbacks are persisted before ID mapping. */
    public function apply(MessageDelivery $row):void {
        if($row->channel!=='whatsapp'||!$row->provider_id||$row->status==='delivered')return;
        $delivered=MessageProviderEvent::where('provider_id',$row->provider_id)->where('status','delivered')->first();
        if($delivered){$row->status='delivered';$row->delivered_at=$delivered->created_at;$row->failure_code=null;$row->save();return;}
        if(MessageProviderEvent::where('provider_id',$row->provider_id)->where('status','failed')->exists()&&in_array($row->status,['accepted','uncertain'],true)){$row->status='failed';$row->failure_code='PROVIDER_DELIVERY_FAILED';$row->save();}
    }
}
