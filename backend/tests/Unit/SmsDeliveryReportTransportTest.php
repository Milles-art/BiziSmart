<?php
use App\Services\SmsDeliveryReportTransport;
use Illuminate\Support\Facades\Http;
uses(Tests\TestCase::class);
beforeEach(function(){config(['messaging.sms.key'=>'test-key','messaging.sms.secret'=>'test-secret']);Http::preventStrayRequests();});
it('uses the fixed authenticated Beem GET and verifies exact report identities',function(){
    Http::fake(['dlrapi.beem.africa/*'=>Http::response([['dest_addr'=>'255750000001','request_id'=>'00123','status'=>'DELIVERED']],200)]);
    expect(app(SmsDeliveryReportTransport::class)->lookup('00123','255750000001'))->toBe('DELIVERED');
    Http::assertSent(fn($r)=>$r->method()==='GET'&&str_starts_with($r->url(),'https://dlrapi.beem.africa/public/v1/delivery-reports?')&&$r['dest_addr']==='255750000001'&&$r['request_id']==='00123'&&$r->hasHeader('Authorization','Basic '.base64_encode('test-key:test-secret')));
});
it('preserves documented pending and undelivered states without inventing delivery',function(){
    Http::fake(['dlrapi.beem.africa/*'=>Http::sequence()->push([['dest_addr'=>'255750000001','request_id'=>'123','status'=>'PENDING']])->push([['dest_addr'=>'255750000001','request_id'=>'123','status'=>'UNDELIVERED']])]);
    expect(app(SmsDeliveryReportTransport::class)->lookup('123','255750000001'))->toBe('PENDING');expect(app(SmsDeliveryReportTransport::class)->lookup('123','255750000001'))->toBe('UNDELIVERED');
});
it('rejects mismatched malformed ambiguous and unknown provider reports',function(){
    $correct=['dest_addr'=>'255750000001','request_id'=>'123','status'=>'DELIVERED'];
    $responses=[[...$correct,'dest_addr'=>'255750000002'],[...$correct,'request_id'=>'321'],[...$correct,'request_id'=>123],[...$correct,'status'=>'SENT']];
    $sequence=Http::sequence();foreach($responses as $row)$sequence->push([$row]);$sequence->push([$correct,$correct])->push($correct)->push([]);
    Http::fake(['dlrapi.beem.africa/*'=>$sequence]);foreach(range(1,7) as $_)expect(app(SmsDeliveryReportTransport::class)->lookup('123','255750000001'))->toBeNull();
});
it('leaves status unknown on transport failures or missing configuration without any send request',function(){
    Http::fake(['dlrapi.beem.africa/*'=>Http::sequence()->push([],401)->push([],404)->push([],503)]);
    foreach(range(1,3) as $_)expect(app(SmsDeliveryReportTransport::class)->lookup('123','255750000001'))->toBeNull();
    config(['messaging.sms.secret'=>null]);expect(app(SmsDeliveryReportTransport::class)->lookup('123','255750000001'))->toBeNull();Http::assertSentCount(3);
    Http::assertNotSent(fn($r)=>$r->method()!=='GET');
});
