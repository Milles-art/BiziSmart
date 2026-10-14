<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;

/** Beem's authenticated read-only report API; never sends or retries a message. */
class SmsDeliveryReportTransport {
    public function lookup(string $requestId,string $recipient):?string {
        $config=config('messaging.sms');
        if(!$config['key']||!$config['secret']||$requestId===''||strlen($requestId)>186||!preg_match('/^255[0-9]{9}$/D',$recipient))return null;
        try {
            $response=Http::withBasicAuth($config['key'],$config['secret'])->acceptJson()->asJson()->timeout(20)->connectTimeout(5)->withoutRedirecting()
                ->get('https://dlrapi.beem.africa/public/v1/delivery-reports',['dest_addr'=>$recipient,'request_id'=>$requestId]);
            if(!$response->successful())return null;
            $reports=$response->json();
            // One recipient was sent. Ambiguous/mismatching reports cannot establish delivery.
            if(!is_array($reports)||!array_is_list($reports)||count($reports)!==1||!is_array($reports[0]))return null;
            $report=$reports[0];
            if(($report['dest_addr']??null)!==$recipient||($report['request_id']??null)!==$requestId)return null;
            return in_array($report['status']??null,['PENDING','DELIVERED','UNDELIVERED'],true)?$report['status']:null;
        }catch(\Throwable){return null;}
    }
}
