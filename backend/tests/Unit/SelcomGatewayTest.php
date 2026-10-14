<?php

use App\Exceptions\ApiException;
use App\Models\Business;
use App\Payments\{SelcomGateway,SelcomMerchant,SelcomSignature};
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{DB,Http};

pest()->extend(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->business = new Business(['name'=>'Test Shop','subdomain'=>'selcom-test']);
    $this->business->id = 71;
    $this->business->public_id = 'biz_01TESTSELCOM';
    $this->business->exists = true;
    app(TenantContext::class)->set($this->business);
    config(['selcom'=>[
        'business_public_id'=>$this->business->publicId(), 'base_url'=>'https://selcom.example',
        'api_key'=>'private-key','api_secret'=>'private-secret','vendor'=>'TEST-VENDOR',
        'webhook_url'=>'https://payments.example/api/v1/payments/selcom/callback',
    ]]);
    $this->gateway = app(SelcomGateway::class);
});

function selcomStatusRow(array $changes = []): array {
    return ['result'=>'SUCCESS','resultcode'=>'000','data'=>[array_replace([
        'order_id'=>'order_123','amount'=>'1000','payment_status'=>'COMPLETED',
        'transid'=>'tx_123','reference'=>'ref_123','channel'=>'MPESA-TZ','phone'=>'255624643714',
    ], $changes)]];
}

it('signs exact scalar values in field order without URL escaping', function () {
    $headers = (new SelcomSignature)->headers(['order_id'=>'order_123','amount'=>1000,'buyer_name'=>'Asha & Co'], 'key', 'secret', '2026-10-10T10:00:00+03:00');
    $signing = 'timestamp=2026-10-10T10:00:00+03:00&order_id=order_123&amount=1000&buyer_name=Asha & Co';
    expect($headers['Digest'])->toBe(base64_encode(hash_hmac('sha256',$signing,'secret',true)))
        ->and($headers['Signed-Fields'])->toBe('order_id,amount,buyer_name')
        ->and($headers['Authorization'])->toBe('SELCOM a2V5');
});

it('creates a signed integer TZS order but never treats acceptance as payment', function () {
    Http::fake(['*'=>Http::response(['result'=>'SUCCESS','resultcode'=>'000','data'=>[['payment_token'=>'secret-token']]])]);
    $this->freezeTime();
    expect($this->gateway->createOrder($this->business,'order_123',1000,'Asha','asha@example.com','255624643714',2))
        ->toBe(['accepted'=>true,'state'=>'pending']);
    Http::assertSent(function(Request $r) {
        $body = $r->data();
        $timestamp = $r->header('Timestamp')[0];
        $expected = (new SelcomSignature)->headers($body,'private-key','private-secret',$timestamp);
        return $r->url()==='https://selcom.example/v1/checkout/create-order-minimal' && $r->method()==='POST'
            && $body['amount']===1000 && $body['currency']==='TZS' && $body['vendor']==='TEST-VENDOR'
            && $body['webhook']===base64_encode('https://payments.example/api/v1/payments/selcom/callback')
            && $r->header('Digest')[0]===$expected['Digest'];
    });
    Http::assertSentCount(1);
});

it('sends the immutable order and transaction identifiers for a wallet push', function () {
    Http::fake(['*'=>Http::response(['result'=>'SUCCESS','resultcode'=>'000'])]);
    expect($this->gateway->push($this->business,'order_123','push_123','255624643714'))->toBe(['accepted'=>true,'state'=>'pending']);
    Http::assertSent(fn(Request $r)=>$r->url()==='https://selcom.example/v1/checkout/wallet-payment'
        && $r->data()===['transid'=>'push_123','order_id'=>'order_123','msisdn'=>'255624643714']);
});

it('confirms only a matching order and amount from the server status query', function () {
    Http::fake(['*'=>Http::response(selcomStatusRow())]);
    expect($this->gateway->status($this->business,'order_123',1000))->toBe([
        'state'=>'confirmed','amount'=>1000,'order_id'=>'order_123','transaction_id'=>'tx_123','reference'=>'ref_123','channel'=>'MPESA-TZ',
    ]);
    Http::assertSent(fn(Request $r)=>$r->method()==='GET' && $r['order_id']==='order_123' && $r->header('Signed-Fields')===['order_id']);
});

it('maps provider payment states independently of envelope success', function (string $status, string $state) {
    Http::fake(['*'=>Http::response(selcomStatusRow(['payment_status'=>$status]))]);
    expect($this->gateway->status($this->business,'order_123',1000))->toBe(['state'=>$state]);
})->with([['PENDING','pending'],['INPROGRESS','pending'],['CANCELLED','failed'],['USERCANCELLED','failed'],['REJECTED','failed'],['FUTURE_STATUS','unknown']]);

it('rejects mismatched or malformed confirmation without exposing provider data', function (array $changes) {
    Http::fake(['*'=>Http::response(json_encode(selcomStatusRow($changes), JSON_PRESERVE_ZERO_FRACTION))]);
    try { $this->gateway->status($this->business,'order_123',1000); $this->fail('Unverified payment accepted.'); }
    catch(ApiException $e) { expect($e->errorCode)->toBe('PAYMENT_RESPONSE_INVALID')->and($e->getMessage())->not->toContain('255624643714'); }
})->with([
    [['order_id'=>'other_order']], [['amount'=>'1001']], [['amount'=>1000.0]], [['amount'=>'1000.00']],
    [['amount'=>'1e3']], [['amount'=>'90071992547409910000']], [['amount'=>null]], [['transid'=>null]],
    [['reference'=>'']], [['channel'=>['MPESA']]], [['currency'=>'USD']], [['vendor'=>'OTHER-VENDOR']],
]);

it('does not mistake an ambiguous envelope for a declined or confirmed payment', function () {
    Http::fake(['*'=>Http::response(['result'=>'AMBIGOUS','resultcode'=>'999','data'=>[]])]);
    expect($this->gateway->status($this->business,'order_123',1000))->toBe(['state'=>'unknown']);
});

it('does not retry uncertain pushes or leak upstream bodies', function (int $status) {
    Http::fake(['*'=>Http::response('private-secret customer phone 255624643714',$status)]);
    try { $this->gateway->push($this->business,'order_123','push_123','255624643714'); $this->fail('Unexpected success.'); }
    catch(ApiException $e) { expect($e->errorCode)->toBe('PAYMENT_OUTCOME_UNKNOWN')->and($e->getMessage())->not->toContain('private-secret'); }
    Http::assertSentCount(1);
})->with([302,400,429,500]);

it('preserves uncertainty on connection failures with one attempt', function () {
    Http::fake(['*'=>Http::failedConnection('private-secret')]);
    expect(fn()=>$this->gateway->push($this->business,'order_123','push_123','255624643714'))
        ->toThrow(ApiException::class,'Payment outcome is uncertain.');
});

it('fails closed before networking when credentials are missing or unsafe', function (string $field, mixed $value) {
    config(['selcom.'.$field=>$value]);
    expect(app(SelcomMerchant::class)->configured($this->business))->toBeFalse();
    expect(fn()=>$this->gateway->status($this->business,'order_123',1000))->toThrow(ApiException::class,'not configured');
    Http::assertNothingSent();
})->with([
    ['api_key',''],['api_secret',null],['vendor',"bad\nvalue"],['business_public_id','biz_other'],
    ['base_url','http://selcom.example'],['base_url','https://user:secret@selcom.example'],
    ['webhook_url','http://localhost/callback'],['webhook_url','https://example.com/path?secret=1'],
]);

it('never reuses configured merchant credentials for a different tenant', function () {
    $other = clone $this->business; $other->id=72; $other->public_id='biz_other';
    expect(fn()=>$this->gateway->status($other,'order_123',1000))->toThrow(AuthorizationException::class);
    app(TenantContext::class)->set($other);
    expect(fn()=>$this->gateway->status($other,'order_123',1000))->toThrow(ApiException::class,'not configured');
    Http::assertNothingSent();
});

it('forbids network calls inside retryable financial transactions', function () {
    DB::shouldReceive('transactionLevel')->once()->andReturn(1);
    expect(fn()=>$this->gateway->status($this->business,'order_123',1000))->toThrow(LogicException::class,'outside database transactions');
    Http::assertNothingSent();
});

it('rejects invalid billing inputs before contacting Selcom', function () {
    expect(fn()=>$this->gateway->push($this->business,'bad&order','tx','255624643714'))->toThrow(InvalidArgumentException::class);
    expect(fn()=>$this->gateway->push($this->business,'order','tx','0624643714'))->toThrow(InvalidArgumentException::class);
    expect(fn()=>$this->gateway->createOrder($this->business,'order',0,'Asha','asha@example.com','255624643714',1))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('authenticates callbacks but excludes unsigned money and phone fields', function () {
    $sig = new SelcomSignature;
    $signed = ['transid'=>'tx_123','order_id'=>'order_123','reference'=>'ref_123','result'=>'SUCCESS','resultcode'=>'000','payment_status'=>'COMPLETED'];
    $time = new DateTimeImmutable('2026-10-10T10:00:00+03:00');
    $headers = $sig->headers($signed,'key','secret',$time->format('c'));
    expect($sig->notification([...$signed,'amount'=>'99999','phone'=>'255624643714'],$headers,'key','secret',$time))->toBe($signed);
});

it('rejects forged stale future or partially signed callback notifications', function (string $change) {
    $sig = new SelcomSignature;
    $payload = ['transid'=>'tx_123','order_id'=>'order_123','reference'=>'ref_123','result'=>'SUCCESS','resultcode'=>'000','payment_status'=>'COMPLETED'];
    $now = new DateTimeImmutable('2026-10-10T10:00:00+03:00');
    $timestamp = match($change) { 'stale'=>$now->modify('-301 seconds'), 'future'=>$now->modify('+301 seconds'), default=>$now };
    if ($change==='partial') unset($payload['resultcode']);
    $headers = $sig->headers($payload,'key','secret',$timestamp->format('c'));
    if ($change==='tamper') $payload['order_id']='order_other';
    if ($change==='digest') $headers['Digest']='forged';
    if ($change==='auth') $headers['Authorization']='SELCOM wrong';
    if ($change==='algorithm') $headers['Digest-Method']='RS256';
    if ($change==='duplicate') $headers['Signed-Fields'].=',order_id';
    if ($change==='nested') $payload['transid']=['value'=>'tx_123'];
    expect($sig->notification($payload,$headers,'key','secret',$now))->toBeNull();
})->with(['stale','future','partial','tamper','digest','auth','algorithm','duplicate','nested']);


it('keeps an unaccepted push uncertain until reconciliation', function () {
    Http::fake(['*'=>Http::response(['result'=>'INPROGRESS','resultcode'=>'111'])]);
    expect($this->gateway->push($this->business,'order_123','push_123','255624643714'))->toBe(['accepted'=>false,'state'=>'unknown']);
    Http::assertSentCount(1);
});

it('rejects malformed JSON and multiple status rows', function (string $body) {
    Http::fake(['*'=>Http::response($body)]);
    expect(fn()=>$this->gateway->status($this->business,'order_123',1000))->toThrow(ApiException::class,'could not be verified');
})->with(['<html>Bad gateway</html>', '{"result":"SUCCESS","resultcode":"000","data":[]}',
    '{"result":"SUCCESS","resultcode":"000","data":[{},{}]}', '{"result":"SUCCESS","resultcode":0}']);


it('rejects coerced decimal money at the transport boundary', function () {
    expect(fn()=>$this->gateway->status($this->business,'order_123',1000.5))->toThrow(InvalidArgumentException::class);
    expect(fn()=>$this->gateway->createOrder($this->business,'order_123',1000.5,'Asha','asha@example.com','255624643714',1))->toThrow(InvalidArgumentException::class);
    expect(app(\App\Payments\MobileMoneyGateway::class))->toBeInstanceOf(SelcomGateway::class);
    Http::assertNothingSent();
});
