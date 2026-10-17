<?php
use App\Models\{Business,Branch,Device,Plan,User,Product,ProductBatch,StockMovement,Sale,SaleItem,SalePayment,TillCashEntry,TillSession,PersonalAccessToken};
use App\Services\{StaffRoleService,StockService,TillService,SaleService};
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\{DB,Concurrency};
use Tests\Concerns\RefreshPaymentDatabase;
use App\Models\{MobilePayment,MobilePaymentNotification};
use App\Services\MobilePaymentService;
use App\Payments\SelcomSignature;
use Illuminate\Support\Facades\Http;
uses(RefreshPaymentDatabase::class);
beforeEach(function () {
    config(['tenancy.domain'=>'bizismart.test']); $this->context=app(TenantContext::class);
    $plan=Plan::create(['code'=>'cash','name'=>'Cash','price_monthly'=>0,'limits'=>['tills'=>5],'features'=>[]]);
    foreach (['alpha','beta'] as $label) {
        $shop=Business::create(['name'=>$label,'subdomain'=>$label,'plan_id'=>$plan->id,'owner_phone'=>'0700000001']); $this->{$label}=$shop;
        $this->context->run($shop,function () use ($label) {
            app(StaffRoleService::class)->provision(); $branch=Branch::create(['name'=>'Main']); $this->{$label.'Branch'}=$branch;
            $device=Device::create(['device_uid'=>'cash-test','name'=>'Cash test','status'=>'approved']); $this->{$label.'Device'}=$device;
            foreach (['cashier','manager','stockkeeper'] as $i=>$role) {
                $u=User::create(['name'=>$role,'phone'=>'071000000'.$i,'pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$branch->id]); app(StaffRoleService::class)->assign($u,$role);
                $token=$u->createToken('cash-test',['*'],now()->addDay()); $token->accessToken->device_id=$device->id; $token->accessToken->save(); $u->withAccessToken($token->accessToken);
                $this->{$label.ucfirst($role)}=$u; $this->{$label.ucfirst($role).'Token'}=$token->plainTextToken;
            }
            $p=Product::create(['name'=>'Test water','quantity_type'=>'counted','unit'=>'bottle','selling_price'=>8500,'buying_price'=>100,'reorder_level_atoms'=>0,'is_active'=>true]); $this->{$label.'Product'}=$p;
            $this->{$label.'Till'}=app(TillService::class)->open($this->{$label.'Cashier'},['operation_id'=>(string)Str::uuid(),'branch_id'=>$branch->publicId(),'opening_float'=>20000])['till'];
            app(StockService::class)->receive($this->{$label.'Stockkeeper'},$p,['operation_id'=>(string)Str::uuid(),'batch_number'=>'UNDATED','qty'=>'20','cost_price'=>100,'expiry_date'=>null]);
        });
    }
    $this->withToken($this->alphaCashierToken);
    Http::preventStrayRequests();
    config(['selcom'=>['business_public_id'=>$this->alpha->publicId(),'base_url'=>'https://selcom.example','api_key'=>'key','api_secret'=>'secret','vendor'=>'VENDOR','webhook_url'=>'https://payments.example/api/v1/payments/selcom/callback']]);
    $this->input=['branch_id'=>$this->alphaBranch->publicId(),'till_id'=>$this->alphaTill['id'],'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1','packaging_id'=>null]]];
});
afterEach(function () { $this->context->clear(); $this->travelBack(); TillCashEntry::flushEventListeners(); \Illuminate\Database\Eloquent\Model::clearBootedModels(); });

function paymentUrl(string $path=''): string{return 'http://alpha.bizismart.test/api/v1/payments'.$path;}
function mobileBody($test,int $mobile=6000): array {
    $sale=[...$test->input,'mobile_amount'=>$mobile];
    $review=$test->postJson('http://alpha.bizismart.test/api/v1/sales/preview',$sale)->assertOk()->json('data');
    return ['sale'=>[...$sale,'cash_tendered'=>3000,'review_hash'=>$review['review_hash']],'buyer_name'=>'Asha','buyer_email'=>'asha@example.com','phone'=>'255624643714'];
}
function mobileAccepted(): void{Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();Http::fake(['*'=>Http::response(['result'=>'SUCCESS','resultcode'=>'000','data'=>[]])]);}
function mobileConfirmFake(string $id,array $changes=[]): void {
    Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();
    Http::fake(['*'=>Http::response(['result'=>'SUCCESS','resultcode'=>'000','data'=>[array_replace([
        'order_id'=>$id,'amount'=>'6000','payment_status'=>'COMPLETED','transid'=>'provider_tx','reference'=>'provider_ref','channel'=>'MPESA-TZ',
    ],$changes)]])]);
}
function notifyMobile($test,string $id,?array $headers=null): void {
    $body=['transid'=>'provider_tx','order_id'=>$id,'reference'=>'provider_ref','result'=>'SUCCESS','resultcode'=>'000','payment_status'=>'COMPLETED'];
    $headers??=(new SelcomSignature)->headers($body,'key','secret',now('Africa/Dar_es_Salaam')->format('c'));
    $test->postJson('/api/v1/payments/selcom/callback',$body,$headers)->assertOk();
}
function startMobile($test): array {mobileAccepted();return $test->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(paymentUrl('/selcom'),mobileBody($test))->assertOk()->json('data');}

it('persists the payment before a single order and push then allocates the verified amount atomically',function(){
    mobileAccepted();$body=mobileBody($this);$key=(string)Str::uuid();
    $this->withHeader('Idempotency-Key',$key);$payment=$this->postJson(paymentUrl('/selcom'),$body)->assertOk()->assertJsonPath('data.status','awaiting')->assertHeader('Cache-Control','no-store, private')->json('data');
    $this->postJson(paymentUrl('/selcom'),$body)->assertOk()->assertJsonPath('data.id',$payment['id']);Http::assertSentCount(2);
    $this->postJson(paymentUrl('/selcom'),[...$body,'buyer_name'=>'Different'])->assertConflict()->assertJsonPath('error.code','OPERATION_ID_REUSED');
    $this->postJson(paymentUrl('/'.$payment['id'].'/complete'))->assertConflict()->assertJsonPath('error.code','PAYMENT_NOT_CONFIRMED');
    notifyMobile($this,$payment['id']);notifyMobile($this,$payment['id']);
    $this->context->run($this->alpha,fn()=>expect(MobilePaymentNotification::count())->toBe(1));
    mobileConfirmFake($payment['id']);$this->getJson(paymentUrl('/'.$payment['id']))->assertOk()->assertJsonPath('data.status','confirmed');
    $sale=$this->postJson(paymentUrl('/'.$payment['id'].'/complete'))->assertOk()->assertJsonPath('data.total',8500)->assertJsonPath('data.change_given',500)->assertJsonPath('data.payments.1.method','mpesa')->assertJsonPath('data.payments.1.amount',6000)->json('data');
    $this->postJson(paymentUrl('/'.$payment['id'].'/complete'))->assertOk()->assertJsonPath('data.id',$sale['id'])->assertJsonPath('data.replayed',true);
    $this->context->run($this->alpha,function(){expect(Sale::count())->toBe(1);expect(MobilePayment::first()->status)->toBe('allocated');expect(ProductBatch::sum('qty_remaining_atoms'))->toBe('19');expect(app(TillService::class)->view((new TillSession)->resolveRouteBinding($this->alphaTill['id']))['expected_cash'])->toBe(22500);});
});

it('rejects unconfigured collection and overselling before contacting Selcom',function(){
    $body=mobileBody($this);config(['selcom.api_key'=>null]);
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(paymentUrl('/selcom'),$body)->assertStatus(503)->assertJsonPath('error.code','PAYMENT_UNCONFIGURED');Http::assertNothingSent();
    config(['selcom.api_key'=>'key']);$sale=[...$this->input,'mobile_amount'=>6000,'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'21']]];
    $review=$this->postJson('http://alpha.bizismart.test/api/v1/sales/preview',$sale)->assertOk()->json('data');
    $body['sale']=[...$sale,'cash_tendered'=>200000,'review_hash'=>$review['review_hash']];
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(paymentUrl('/selcom'),$body)->assertStatus(400)->assertJsonPath('error.code','INSUFFICIENT_STOCK');Http::assertNothingSent();
});

it('recovers an uncertain push using the saved order without repeating collection',function(){
    $body=mobileBody($this);$key=(string)Str::uuid();$calls=0;
    Http::fake(function()use(&$calls){$calls++;if($calls===1)return Http::response(['result'=>'SUCCESS','resultcode'=>'000']);throw new \Illuminate\Http\Client\ConnectionException('timeout');});
    $this->withHeader('Idempotency-Key',$key);$payment=$this->postJson(paymentUrl('/selcom'),$body)->assertOk()->assertJsonPath('data.status','unknown')->json('data');
    $this->postJson(paymentUrl('/selcom'),$body)->assertOk()->assertJsonPath('data.id',$payment['id']);expect($calls)->toBe(2);
    $this->travel(4)->minutes();mobileConfirmFake($payment['id']);$this->getJson(paymentUrl('/'.$payment['id']))->assertOk()->assertJsonPath('data.status','confirmed');
    $this->postJson(paymentUrl('/'.$payment['id'].'/complete'))->assertOk();
});

it('rejects forged callbacks and never trusts unsigned amount fields',function(){
    $p=startMobile($this);$body=['transid'=>'tx','order_id'=>$p['id'],'reference'=>'ref','result'=>'SUCCESS','resultcode'=>'000','payment_status'=>'COMPLETED'];
    $headers=(new SelcomSignature)->headers($body,'key','secret',now()->format('c'));$headers['Digest']='forged';
    $this->postJson('/api/v1/payments/selcom/callback',$body,$headers)->assertUnauthorized()->assertJsonPath('error.code','PAYMENT_SIGNATURE_INVALID');
    $this->postJson('/api/v1/payments/selcom/callback',[...$body,'order_id'=>'mpay_'.Str::ulid()])->assertOk();
    $headers=(new SelcomSignature)->headers($body,'key','secret',now()->format('c'));
    $this->postJson('/api/v1/payments/selcom/callback',[...$body,'amount'=>'1'],$headers)->assertOk();
    mobileConfirmFake($p['id'],['amount'=>'1']);$this->getJson(paymentUrl('/'.$p['id']))->assertOk()->assertJsonPath('data.status','unknown')->assertJsonPath('data.last_error','PAYMENT_RESPONSE_INVALID');
    $this->postJson(paymentUrl('/'.$p['id'].'/complete'))->assertConflict();
});

it('keeps paid money unallocated when stock or current prices cannot complete checkout',function(){
    $p=startMobile($this);notifyMobile($this,$p['id']);mobileConfirmFake($p['id']);$this->getJson(paymentUrl('/'.$p['id']))->assertOk();
    $this->context->run($this->alpha,fn()=>$this->alphaProduct->update(['selling_price'=>9000]));
    $this->postJson(paymentUrl('/'.$p['id'].'/complete'))->assertConflict()->assertJsonPath('error.code','SALE_CHANGED');
    $this->travel(61)->minutes();$this->getJson(paymentUrl('/'.$p['id']))->assertOk()->assertJsonPath('data.status','confirmed')->assertJsonPath('data.owner_review_required',true);
    $this->context->run($this->alpha,function(){expect(Sale::count())->toBe(0);expect(TillCashEntry::count())->toBe(0);expect(MobilePayment::first()->sale_id)->toBeNull();});
});

it('isolates payment records by tenant actor device and exact checkout body',function(){
    $p=startMobile($this);
    $this->withToken($this->betaCashierToken)->getJson('http://beta.bizismart.test/api/v1/payments/'.$p['id'])->assertNotFound();
    $this->withToken($this->alphaManagerToken)->getJson(paymentUrl('/'.$p['id']))->assertForbidden();
    $this->withToken($this->alphaCashierToken);notifyMobile($this,$p['id']);mobileConfirmFake($p['id']);$this->getJson(paymentUrl('/'.$p['id']))->assertOk();
    $saved=$this->context->run($this->alpha,fn()=>MobilePayment::first()->request['sale']);
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('http://alpha.bizismart.test/api/v1/sales',[...$saved,'cash_tendered'=>3001,'payment_id'=>$p['id']])->assertConflict()->assertJsonPath('error.code','PAYMENT_MISMATCH');
    $this->getJson(paymentUrl('/mine'))->assertOk()->assertJsonCount(1,'data.payments')->assertJsonMissingPath('data.payments.0.request');
});

it('does not allocate a reused provider reference twice',function(){
    $first=startMobile($this);notifyMobile($this,$first['id']);mobileConfirmFake($first['id']);$this->getJson(paymentUrl('/'.$first['id']))->assertOk();
    $this->postJson(paymentUrl('/'.$first['id'].'/complete'))->assertOk();
    $second=startMobile($this);notifyMobile($this,$second['id']);mobileConfirmFake($second['id']);
    $this->getJson(paymentUrl('/'.$second['id']))->assertConflict()->assertJsonPath('error.code','PAYMENT_REFERENCE_REUSED');
    $this->context->run($this->alpha,fn()=>expect(MobilePayment::whereNotNull('confirmed_at')->count())->toBe(1));
});

it('requires fresh device authority and prevents immutable payment identity changes',function(){
    $p=startMobile($this);
    $this->context->run($this->alpha,function(){expect(fn()=>MobilePayment::first()->update(['amount'=>1]))->toThrow(LogicException::class);$this->alphaDevice->update(['status'=>'blocked']);});
    $this->postJson(paymentUrl('/'.$p['id'].'/complete'))->assertUnauthorized();
});


it('blocks a second unresolved payment and refuses allocating with a different operation UUID',function(){
    $p=startMobile($this);$body=mobileBody($this);
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(paymentUrl('/selcom'),$body)->assertConflict()->assertJsonPath('error.code','PAYMENT_PENDING');
    Http::assertSentCount(2);
    notifyMobile($this,$p['id']);mobileConfirmFake($p['id']);$this->getJson(paymentUrl('/'.$p['id']))->assertOk();
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('http://alpha.bizismart.test/api/v1/sales',[...$body['sale'],'payment_id'=>$p['id']])->assertConflict()->assertJsonPath('error.code','PAYMENT_MISMATCH');
});

it('allocates cash mobile and customer credit without adding mobile money to cash or changing exact debt',function(){
    $customer=$this->context->run($this->alpha,fn()=>app(\App\Services\CustomerService::class)->save($this->alphaCashier,['name'=>'Asha','phone'=>'0624643714','credit_limit'=>20000]));
    $sale=[...$this->input,'customer_id'=>$customer->publicId(),'credit_amount'=>1500,'mobile_amount'=>5000];
    $review=$this->postJson('http://alpha.bizismart.test/api/v1/sales/preview',$sale)->assertOk()->assertJsonPath('data.cash_allocated',2000)->json('data');
    mobileAccepted();$body=['sale'=>[...$sale,'cash_tendered'=>2500,'review_hash'=>$review['review_hash']],'buyer_name'=>'Asha','buyer_email'=>'asha@example.com','phone'=>'255624643714'];
    $p=$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(paymentUrl('/selcom'),$body)->assertOk()->json('data');
    notifyMobile($this,$p['id']);mobileConfirmFake($p['id'],['amount'=>'5000']);$this->getJson(paymentUrl('/'.$p['id']))->assertOk();
    $this->postJson(paymentUrl('/'.$p['id'].'/complete'))->assertOk()->assertJsonPath('data.change_given',500)->assertJsonCount(3,'data.payments');
    $this->context->run($this->alpha,function()use($customer){expect($customer->fresh()->outstanding_balance)->toBe(1500);expect(app(TillService::class)->view((new TillSession)->resolveRouteBinding($this->alphaTill['id']))['expected_cash'])->toBe(22000);});
});

it('reconciles due payments with the scheduler and leaves a declined payment unsold',function(){
    $p=startMobile($this);$this->travel(4)->minutes();mobileConfirmFake($p['id'],['payment_status'=>'REJECTED']);
    $this->artisan('payments:reconcile')->assertSuccessful();
    $this->getJson(paymentUrl('/'.$p['id']))->assertOk()->assertJsonPath('data.status','failed');
    $this->postJson(paymentUrl('/'.$p['id'].'/complete'))->assertConflict();
    $this->context->run($this->alpha,fn()=>expect(Sale::count())->toBe(0));
});


it('records an owner-approved external refund once and never changes stock cash or calls a transfer API',function(){
 $p=startMobile($this);notifyMobile($this,$p['id']);mobileConfirmFake($p['id']);$this->getJson(paymentUrl('/'.$p['id']))->assertOk();
 $owner=$this->context->run($this->alpha,function(){
  $u=User::create(['name'=>'Owner','phone'=>'0700000001','pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$this->alphaBranch->id]);app(StaffRoleService::class)->assign($u,'owner');
  $u->totp_secret='JBSWY3DPEHPK3PXP';$u->totp_confirmed_at=now();$u->save();$token=$u->createToken('manual',['*'],now()->addDay());$token->accessToken->device_id=$this->alphaDevice->id;$token->accessToken->save();
  return [$u->withAccessToken($token->accessToken),$token->plainTextToken];
 });
 $this->withToken($owner[1]);$body=['operation_id'=>(string)Str::uuid(),'external_reference'=>'EXTERNAL-REFUND-123','reason'=>'Unavailable goods after confirmation','refund_completed'=>true];
 $review=$this->postJson(paymentUrl('/'.$p['id'].'/manual-refund/preview'),$body)->assertOk()->assertJsonPath('data.amount',6000)->assertJsonPath('data.external_transfer_executed',false)->json('data');
 $code=app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP');
 $approval=$this->postJson('http://alpha.bizismart.test/api/v1/auth/supervisor-check',['phone'=>'0700000001','pin'=>'1234','code'=>$code,'permission'=>'refund','operation_id'=>$body['operation_id'],'payload_hash'=>$review['review_hash']])->assertOk()->json('data');
 $commit=[...$body,'review_hash'=>$review['review_hash'],'approval_token'=>$approval['approval_token']];
 $result=$this->postJson(paymentUrl('/'.$p['id'].'/manual-refund'),$commit)->assertOk()->assertJsonPath('data.external_transfer_executed',false)->json('data');
 $this->postJson(paymentUrl('/'.$p['id'].'/manual-refund'),$commit)->assertOk()->assertJsonPath('data.id',$result['id'])->assertJsonPath('data.replayed',true);
 $this->getJson(paymentUrl())->assertOk()->assertJsonCount(0,'data.payments');
 $this->withToken($this->alphaCashierToken);$this->postJson(paymentUrl('/'.$p['id'].'/complete'))->assertConflict();
 notifyMobile($this,$p['id']);Http::assertSentCount(1);
 $this->context->run($this->alpha,function(){expect(MobilePayment::first()->status)->toBe('manual_refunded');expect(\App\Models\ManualMobileRefund::count())->toBe(1);expect(TillCashEntry::count())->toBe(0);expect(ProductBatch::sum('qty_remaining_atoms'))->toBe('20');});
});

it('rolls back a confirmed payment allocation with stock and cash if the cash ledger fails',function(){
 $p=startMobile($this);notifyMobile($this,$p['id']);mobileConfirmFake($p['id']);$this->getJson(paymentUrl('/'.$p['id']))->assertOk();
 TillCashEntry::created(fn()=>throw new RuntimeException('Injected ledger failure'));
 $this->postJson(paymentUrl('/'.$p['id'].'/complete'))->assertStatus(500);
 $this->context->run($this->alpha,function(){expect(Sale::count())->toBe(0);expect(MobilePayment::first()->status)->toBe('confirmed');expect(ProductBatch::sum('qty_remaining_atoms'))->toBe('20');expect(TillCashEntry::count())->toBe(0);});
});

it('settles only selected invoices with verified Selcom money without crediting drawer cash',function(){
 $customer=$this->context->run($this->alpha,fn()=>app(\App\Services\CustomerService::class)->save($this->alphaCashier,['name'=>'Asha','phone'=>null,'credit_limit'=>30000]));
 $sales=[];
 foreach([1,2] as $n){$body=[...$this->input,'customer_id'=>$customer->publicId(),'credit_amount'=>8500];$review=$this->postJson('http://alpha.bizismart.test/api/v1/sales/preview',$body)->assertOk()->json('data');$sales[]=$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('http://alpha.bizismart.test/api/v1/sales',[...$body,'cash_tendered'=>0,'review_hash'=>$review['review_hash']])->assertCreated()->json('data.id');}
 $collection=['method'=>'mpesa','till_id'=>$this->alphaTill['id'],'amount'=>6000,'allocations'=>[['sale_id'=>$sales[1],'amount'=>6000]]];
 $review=$this->postJson('http://alpha.bizismart.test/api/v1/customers/'.$customer->publicId().'/payments/preview',$collection)->assertOk()->json('data');
 $body=['collection'=>['customer_id'=>$customer->publicId(),...$collection,'review_hash'=>$review['review_hash']],'buyer_name'=>'Asha','buyer_email'=>'asha@example.com','phone'=>'255624643714'];
 mobileAccepted();$key=(string)Str::uuid();$p=$this->withHeader('Idempotency-Key',$key)->postJson(paymentUrl('/selcom'),$body)->assertOk()->assertJsonPath('data.purpose','debt')->json('data');
 $this->postJson(paymentUrl('/'.$p['id'].'/complete'))->assertConflict();
 notifyMobile($this,$p['id']);mobileConfirmFake($p['id']);$this->getJson(paymentUrl('/'.$p['id']))->assertOk();
 $result=$this->postJson(paymentUrl('/'.$p['id'].'/complete'))->assertOk()->assertJsonPath('data.method','mpesa')->assertJsonPath('data.amount',6000)->json('data');
 $this->postJson(paymentUrl('/'.$p['id'].'/complete'))->assertOk()->assertJsonPath('data.id',$result['id'])->assertJsonPath('data.replayed',true);
 $this->context->run($this->alpha,function(){
     $owner=User::create(['name'=>'Owner','phone'=>'0700000099','pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$this->alphaBranch->id]);
     app(StaffRoleService::class)->assign($owner,'owner');
     expect(app(MobilePaymentService::class)->ownerList($owner)['payments'])->toBe([]);
 });
 $this->context->run($this->alpha,function()use($customer,$sales){expect($customer->fresh()->outstanding_balance)->toBe(11000);expect(TillCashEntry::count())->toBe(0);expect(MobilePayment::first()->status)->toBe('allocated');$first=(new Sale)->resolveRouteBinding($sales[0]);$second=(new Sale)->resolveRouteBinding($sales[1]);expect(\App\Models\SaleDebt::where('sale_id',$first->id)->first()->remaining_amount)->toBe(8500);expect(\App\Models\SaleDebt::where('sale_id',$second->id)->first()->remaining_amount)->toBe(2500);});
 $return=['operation_id'=>(string)Str::uuid(),'sale_id'=>$sales[1],'till_id'=>$this->alphaTill['id'],'reason'=>'Returned customer purchase','items'=>[['line_index'=>0,'qty'=>'1','disposition'=>'sellable']]];
 $this->postJson('http://alpha.bizismart.test/api/v1/returns/preview',$return)->assertOk()->assertJsonPath('data.debt_reduction',2500)->assertJsonPath('data.cash_refund',0)->assertJsonPath('data.mobile_refund',6000);
});

it('returns a split-payment receipt proportionally with an approved external mobile refund and no payout request',function(){
 $p=startMobile($this);notifyMobile($this,$p['id']);mobileConfirmFake($p['id']);$this->getJson(paymentUrl('/'.$p['id']))->assertOk();$sale=$this->postJson(paymentUrl('/'.$p['id'].'/complete'))->assertOk()->json('data');
 $base=['operation_id'=>(string)Str::uuid(),'sale_id'=>$sale['id'],'till_id'=>$this->alphaTill['id'],'reason'=>'Unwanted purchase','items'=>[['line_index'=>0,'qty'=>'1','disposition'=>'sellable']]];
 $this->postJson('http://alpha.bizismart.test/api/v1/returns/preview',$base)->assertOk()->assertJsonPath('data.cash_refund',2500)->assertJsonPath('data.mobile_refund',6000);
 $body=[...$base,'mobile_refund_reference'=>'RETURN-123','mobile_refund_completed'=>true];
 $review=$this->postJson('http://alpha.bizismart.test/api/v1/returns/preview',$body)->assertOk()->json('data');
 $grant=$this->postJson('http://alpha.bizismart.test/api/v1/auth/supervisor-check',['phone'=>$this->alphaManager->phone,'pin'=>'1234','permission'=>'refund','operation_id'=>$base['operation_id'],'payload_hash'=>$review['review_hash']])->assertOk()->json('data.approval_token');
 $commit=[...$body,'review_hash'=>$review['review_hash'],'approval_token'=>$grant];
 $result=$this->withHeader('Idempotency-Key',$base['operation_id'])->postJson('http://alpha.bizismart.test/api/v1/returns',$commit)->assertCreated()->assertJsonPath('data.cash_paid',2500)->assertJsonPath('data.mobile_refund',6000)->assertJsonPath('data.external_transfer_executed',false)->json('data');
 $this->postJson('http://alpha.bizismart.test/api/v1/returns',$commit)->assertOk()->assertJsonPath('data.id',$result['id'])->assertJsonPath('data.replayed',true);
 Http::assertSentCount(1);
 $this->context->run($this->alpha,function(){expect(app(TillService::class)->view((new TillSession)->resolveRouteBinding($this->alphaTill['id']))['expected_cash'])->toBe(20000);expect(ProductBatch::sum('qty_remaining_atoms'))->toBe('20');expect(\App\Models\SaleReturn::count())->toBe(1);});
});

it('rejects an extraneous discount grant on an undiscounted mobile sale before provider dispatch',function(){
 $body=mobileBody($this);$body['sale']['approval_token']=str_repeat('a',64);mobileAccepted();
 $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(paymentUrl('/selcom'),$body)->assertUnprocessable()->assertJsonPath('error.code','VALIDATION_ERROR');
 Http::assertNothingSent();
 $this->context->run($this->alpha,function(){expect(MobilePayment::count())->toBe(0);expect(Sale::count())->toBe(0);expect(TillCashEntry::count())->toBe(0);expect(ProductBatch::sum('qty_remaining_atoms'))->toBe('20');});
});

it('requires approved mobile discounts and permits fresh approval after provider confirmation outlives its grant',function(){
 $key=(string)Str::uuid();$sale=[...$this->input,'mobile_amount'=>6000,'discount'=>500,'discount_reason'=>'Checkout promotion','items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1','packaging_id'=>null,'discount'=>500]]];
 $review=$this->postJson('http://alpha.bizismart.test/api/v1/sales/preview',$sale)->assertOk()->assertJsonPath('data.total',7500)->json('data');
 $body=['sale'=>[...$sale,'cash_tendered'=>1500,'review_hash'=>$review['review_hash']],'buyer_name'=>'Asha','buyer_email'=>'asha@example.com','phone'=>'255624643714'];mobileAccepted();
 $this->withHeader('Idempotency-Key',$key)->postJson(paymentUrl('/selcom'),$body)->assertForbidden()->assertJsonPath('error.code','APPROVAL_REQUIRED');Http::assertNothingSent();
 $approve=fn()=>$this->postJson('http://alpha.bizismart.test/api/v1/auth/supervisor-check',['phone'=>$this->alphaManager->phone,'pin'=>'1234','permission'=>'give_discount','operation_id'=>$key,'payload_hash'=>$review['review_hash']])->assertOk()->json('data.approval_token');
 $body['sale']['approval_token']=$approve();$p=$this->postJson(paymentUrl('/selcom'),$body)->assertOk()->json('data');
 notifyMobile($this,$p['id']);$this->travel(6)->minutes();mobileConfirmFake($p['id']);$this->getJson(paymentUrl('/'.$p['id']))->assertOk()->assertJsonPath('data.status','confirmed');
 $this->postJson(paymentUrl('/'.$p['id'].'/complete'))->assertForbidden()->assertJsonPath('error.code','INVALID_APPROVAL');
 $this->postJson(paymentUrl('/'.$p['id'].'/review'))->assertOk()->assertJsonPath('data.operation_id',$key)->assertJsonPath('data.review.total',7500);
 $grant=$approve();$result=$this->postJson(paymentUrl('/'.$p['id'].'/complete'),['approval_token'=>$grant])->assertOk()->assertJsonPath('data.total',7500)->json('data');
 $this->postJson(paymentUrl('/'.$p['id'].'/complete'))->assertOk()->assertJsonPath('data.id',$result['id'])->assertJsonPath('data.replayed',true);
 $this->context->run($this->alpha,function(){expect(Sale::count())->toBe(1);expect(TillCashEntry::sum('amount'))->toBe('1500');expect(ProductBatch::sum('qty_remaining_atoms'))->toBe('19');});
});

function mobileProcess(int $businessId,int $actorId,int $tokenId,array $body,string $key,?string $id=null): Closure {
 return static function()use($businessId,$actorId,$tokenId,$body,$key,$id){
  if(!app()->environment('testing')||!str_ends_with(config('database.connections.mysql.database'),'_test'))throw new LogicException('Dedicated test database required.');
  $business=Business::findOrFail($businessId);app(TenantContext::class)->set($business);
  config(['selcom'=>['business_public_id'=>$business->publicId(),'base_url'=>'https://selcom.example','api_key'=>'key','api_secret'=>'secret','vendor'=>'VENDOR','webhook_url'=>'https://payments.example/api/v1/payments/selcom/callback']]);
  Http::preventStrayRequests();Http::fake(['*'=>Http::response(['result'=>'SUCCESS','resultcode'=>'000','data'=>[]])]);
  $actor=User::findOrFail($actorId)->withAccessToken(PersonalAccessToken::findOrFail($tokenId));
  if($id){$p=(new MobilePayment)->resolveRouteBinding($id);$r=app(MobilePaymentService::class)->complete($actor,$p);return ['id'=>$r['id'],'replayed'=>$r['replayed']];}
  $r=app(MobilePaymentService::class)->begin($actor,$body,$key);return ['id'=>$r['id'],'calls'=>count(Http::recorded())];
 };
}

it('serializes duplicate initiation and independent-process confirmed sale allocation',function(){
 $body=mobileBody($this);$key=(string)Str::uuid();$actor=$this->alphaCashier;
 $args=[$this->alpha->id,$actor->id,$actor->currentAccessToken()->id,$body,$key];
 $started=Concurrency::driver('process')->run([mobileProcess(...$args),mobileProcess(...$args)]);
 expect($started[0]['id'])->toBe($started[1]['id']);expect(array_sum(array_column($started,'calls')))->toBe(2);
 notifyMobile($this,$started[0]['id']);mobileConfirmFake($started[0]['id']);$this->getJson(paymentUrl('/'.$started[0]['id']))->assertOk();
 $results=Concurrency::driver('process')->run([mobileProcess(...[...$args,$started[0]['id']]),mobileProcess(...[...$args,$started[0]['id']])]);
 expect($results[0]['id'])->toBe($results[1]['id']);expect(array_column($results,'replayed'))->toContain(true,false);
 $this->context->run($this->alpha,function(){expect(MobilePayment::count())->toBe(1);expect(Sale::count())->toBe(1);expect(SalePayment::where('method','mpesa')->count())->toBe(1);expect(ProductBatch::sum('qty_remaining_atoms'))->toBe('19');});
});
