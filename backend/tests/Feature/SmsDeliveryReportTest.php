<?php
use App\Models\{Business,Plan,MessageDelivery};
use App\Services\{SmsDeliveryReportService,MessageTransport};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);
beforeEach(function(){
    $this->freezeTime();$this->context=app(TenantContext::class);
    $features=array_fill_keys(config('platform.features'),true);$features['tra']=false;
    $plan=Plan::create(['code'=>'sms-report-tests','name'=>'SMS reports','price_monthly'=>0,'limits'=>[],'features'=>$features]);
    $this->alpha=Business::create(['name'=>'Alpha','subdomain'=>'alpha','plan_id'=>$plan->id,'owner_phone'=>'255700000001']);
    $this->beta=Business::create(['name'=>'Beta','subdomain'=>'beta','plan_id'=>$plan->id,'owner_phone'=>'255700000002']);
    $this->context->set($this->alpha);config(['messaging.business_public_id'=>$this->alpha->publicId(),'messaging.sms.key'=>'test-key','messaging.sms.secret'=>'test-secret','messaging.sms.sender'=>'TEST']);Http::preventStrayRequests();
    $this->delivery=MessageDelivery::create(['event_key'=>hash('sha256','sms-report'),'channel'=>'sms','purpose'=>'receipt','recipient'=>'255750000001','message'=>'Test receipt','status'=>'accepted','provider_id'=>'beem:00123','accepted_at'=>now()->subMinutes(6),'updated_at'=>now()->subMinutes(6)]);
});
afterEach(function(){$this->context->clear();$this->travelBack();});
function smsReport(string $status='DELIVERED'):array{return [['dest_addr'=>'255750000001','request_id'=>'00123','status'=>$status]];}
it('records authenticated exact SMS delivery once and never sends or regresses a terminal record',function(){
    Http::fake(['dlrapi.beem.africa/*'=>Http::response(smsReport(),200)]);
    app(SmsDeliveryReportService::class)->reconcileTenant();$row=$this->delivery->fresh();expect($row->status)->toBe('delivered')->and($row->delivered_at)->not->toBeNull()->and($row->provider_id)->toBe('beem:00123');
    app(SmsDeliveryReportService::class)->poll($row->id);Http::assertSentCount(1);Http::assertNotSent(fn($r)=>$r->method()!=='GET');
});
it('uses durable five-minute GET claims and preserves accepted status for pending reports',function(){
    $this->delivery->accepted_at=now();$this->delivery->save();Http::fake(['dlrapi.beem.africa/*'=>Http::sequence()->push(smsReport('PENDING'))->push(smsReport('UNDELIVERED'))]);
    app(SmsDeliveryReportService::class)->poll($this->delivery->id);Http::assertNothingSent();
    $this->travel(5)->minutes();app(SmsDeliveryReportService::class)->poll($this->delivery->id);expect($this->delivery->fresh()->status)->toBe('accepted');Http::assertSentCount(1);
    app(SmsDeliveryReportService::class)->poll($this->delivery->id);$this->travel(4)->minutes();app(SmsDeliveryReportService::class)->poll($this->delivery->id);Http::assertSentCount(1);
    $this->travel(1)->minutes();app(SmsDeliveryReportService::class)->poll($this->delivery->id);expect($this->delivery->fresh()->status)->toBe('failed')->and($this->delivery->fresh()->failure_code)->toBe('PROVIDER_DELIVERY_FAILED');
    app(SmsDeliveryReportService::class)->poll($this->delivery->id);Http::assertSentCount(2);
});
it('keeps cancelled state when delivery evidence arrives after a current-state change',function(){
    Http::fake(function($request){$row=$this->delivery->fresh();$row->status='cancelled';$row->failure_code='CONSENT_REVOKED';$row->save();return Http::response(smsReport(),200);});
    app(SmsDeliveryReportService::class)->poll($this->delivery->id);expect($this->delivery->fresh()->status)->toBe('cancelled')->and($this->delivery->fresh()->delivered_at)->toBeNull();Http::assertSentCount(1);
});
it('fails closed for foreign rows missing credentials or disabled messaging and does not infer delivery',function(){
    Http::fake(['dlrapi.beem.africa/*'=>Http::response([['dest_addr'=>'255750000002','request_id'=>'00123','status'=>'DELIVERED']],200)]);
    $this->context->set($this->beta);config(['messaging.business_public_id'=>$this->beta->publicId()]);app(SmsDeliveryReportService::class)->poll($this->delivery->id);Http::assertNothingSent();
    $this->context->set($this->alpha);config(['messaging.business_public_id'=>$this->alpha->publicId(),'messaging.sms.secret'=>null]);app(SmsDeliveryReportService::class)->poll($this->delivery->id);Http::assertNothingSent();
    config(['messaging.sms.secret'=>'test-secret']);$shop=Business::find($this->alpha->id);$settings=$shop->settings??[];$settings['feature_overrides']=['messaging'=>false];$shop->settings=$settings;$shop->save();app(SmsDeliveryReportService::class)->poll($this->delivery->id);Http::assertNothingSent();
    $settings['feature_overrides']['messaging']=true;$shop->settings=$settings;$shop->save();app(SmsDeliveryReportService::class)->poll($this->delivery->id);expect($this->delivery->fresh()->status)->toBe('accepted')->and($this->delivery->fresh()->delivered_at)->toBeNull();Http::assertSentCount(1);
});
it('sends Beem a documented integer recipient reference for the single-recipient request',function(){
    Http::fake(['apisms.beem.africa/*'=>Http::response(['successful'=>true,'code'=>100,'request_id'=>'999'],200)]);
    expect(app(MessageTransport::class)->send($this->delivery))->toBe(['status'=>'accepted','provider_id'=>'beem:999']);
    Http::assertSent(fn($r)=>$r->method()==='POST'&&$r['encoding']===0&&$r['recipients'][0]['recipient_id']===1&&$r['recipients'][0]['dest_addr']==='255750000001');
});
it('sends unchanged GSM names and blocks unsupported Unicode before any HTTP request',function(){
    Http::fake(['apisms.beem.africa/*'=>Http::response(['successful'=>true,'code'=>100,'request_id'=>'998'],200)]);
    foreach(['Karibu dukani TSh 1200','Café Ångström','Price {offer} [kg] \\ €'] as $text){$this->delivery->message=$text;expect(app(MessageTransport::class)->send($this->delivery)['status'])->toBe('accepted');Http::assertSent(fn($r)=>$r['encoding']===0&&$r['message']===$text);}
    Http::assertSentCount(3);
    foreach(['Emoji 🙂 receipt','商品 receipt','Owner’s receipt'] as $text){$this->delivery->message=$text;expect(app(MessageTransport::class)->send($this->delivery))->toBe(['status'=>'blocked','failure_code'=>'SMS_ENCODING_UNVERIFIED']);}
    Http::assertSentCount(3);
});
