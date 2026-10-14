<?php
namespace App\Services;
use App\Models\{Business,MessageDelivery};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
class MessageTransport {
    public function capabilities():array {
        $bound=Business::findOrFail(app(TenantContext::class)->requireId())->publicId()===config('messaging.business_public_id');
        $wa=config('messaging.whatsapp');$sms=config('messaging.sms');
        $w=$bound&&$wa['token']&&preg_match('/^[0-9]+$/D',(string)$wa['phone_number_id'])&&preg_match('/^v[0-9]+\.[0-9]+$/D',(string)$wa['version'])&&$wa['template']&&$wa['app_secret']&&$wa['verify_token'];
        $s=$bound&&$sms['key']&&$sms['secret']&&$sms['sender'];
        return ['whatsapp'=>['ready'=>(bool)$w,'reason'=>$w?null:'provider_setup_required'],'sms'=>['ready'=>(bool)$s,'reason'=>$s?null:'provider_setup_required']];
    }
    public function send(MessageDelivery $row):array {
        if(!$this->capabilities()[$row->channel]['ready'])return ['status'=>'blocked','failure_code'=>'PROVIDER_NOT_CONFIGURED'];
        // Beem currently documents encoding 0 (GSM), but no verified Unicode selector. Preserve source text.
        if($row->channel==='sms'&&!\App\Support\GsmSmsEncoding::supports($row->message))return ['status'=>'blocked','failure_code'=>'SMS_ENCODING_UNVERIFIED'];
        try {
            if($row->channel==='whatsapp'){$c=config('messaging.whatsapp');$response=Http::withToken($c['token'])->acceptJson()->timeout(20)->connectTimeout(5)->withoutRedirecting()->post('https://graph.facebook.com/'.$c['version'].'/'.$c['phone_number_id'].'/messages',['messaging_product'=>'whatsapp','to'=>$row->recipient,'type'=>'template','template'=>['name'=>$c['template'],'language'=>['code'=>$c['language']],'components'=>[['type'=>'body','parameters'=>[['type'=>'text','text'=>$row->message]]]]]]);$id=$response->json('messages.0.id');$ok=$response->successful()&&is_string($id)&&$id!==''&&strlen($id)<=191;}
            else {$c=config('messaging.sms');$response=Http::withBasicAuth($c['key'],$c['secret'])->acceptJson()->timeout(20)->connectTimeout(5)->withoutRedirecting()->post('https://apisms.beem.africa/v1/send',['source_addr'=>$c['sender'],'encoding'=>0,'schedule_time'=>'','message'=>$row->message,'recipients'=>[['recipient_id'=>1,'dest_addr'=>$row->recipient]]]);$id=$response->json('request_id');$ok=$response->successful()&&$response->json('successful')===true&&in_array($response->json('code'),[100,'100'],true)&&(is_int($id)||is_string($id))&&strlen((string)$id)>0&&strlen((string)$id)<=186;$id=$ok?'beem:'.$id:null;}
            if($ok)return ['status'=>'accepted','provider_id'=>$id];
            // Definite 4xx rejection can be reviewed; timeouts/5xx are uncertain and never auto-retried.
            return ['status'=>$response->clientError()?'failed':'uncertain','failure_code'=>$response->clientError()?'PROVIDER_REJECTED':'PROVIDER_OUTCOME_UNKNOWN'];
        }catch(\Throwable){return ['status'=>'uncertain','failure_code'=>'PROVIDER_OUTCOME_UNKNOWN'];}
    }
}
