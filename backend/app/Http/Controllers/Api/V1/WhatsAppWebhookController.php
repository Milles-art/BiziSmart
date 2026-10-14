<?php
namespace App\Http\Controllers\Api\V1;
use App\Models\{Business,MessageDelivery,MessageProviderEvent};
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class WhatsAppWebhookController {
    public function __invoke(Request $request,TenantContext $context){
        if($request->isMethod('GET')){if(!config('messaging.whatsapp.verify_token')||!hash_equals((string)config('messaging.whatsapp.verify_token'),(string)$request->query('hub_verify_token'))||$request->query('hub_mode')!=='subscribe')abort(403);return response((string)$request->query('hub_challenge'),200)->header('Cache-Control','no-store');}
        $secret=config('messaging.whatsapp.app_secret');$signature=$request->header('X-Hub-Signature-256');if(!$secret||!is_string($signature)||!hash_equals('sha256='.hash_hmac('sha256',$request->getContent(),$secret),$signature))abort(401);
        if(strlen($request->getContent())>131072)abort(413);
        $shop=(new Business)->resolveRouteBindingQuery(Business::where('is_active',true),config('messaging.business_public_id'))->firstOrFail();
        $context->run($shop,function()use($request){
            $entries=$request->input('entry',[]);if(!is_array($entries))return;
            foreach($entries as $entry){if(!is_array($entry['changes']??null))continue;
                foreach($entry['changes'] as $change){$value=$change['value']??[];
                    if(($value['metadata']['phone_number_id']??null)!==config('messaging.whatsapp.phone_number_id')||!is_array($value['statuses']??null))continue;
                    foreach($value['statuses'] as $status){
                        $id=$status['id']??null;$state=$status['status']??null;
                        if(!is_string($id)||strlen($id)>191||!in_array($state,['delivered','read','failed'],true))continue;
                        DB::transaction(function()use($id,$state){
                            MessageProviderEvent::firstOrCreate(['provider_id'=>$id,'status'=>$state==='read'?'delivered':$state]);
                            $row=MessageDelivery::where('channel','whatsapp')->where('provider_id',$id)->lockForUpdate()->first();
                            if($row)app(\App\Services\MessageEvidenceService::class)->apply($row);
                        },3);
                    }
                }
            }
        });
        return response()->json(['received'=>true])->header('Cache-Control','no-store');
    }
}
