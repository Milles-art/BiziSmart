<?php
use App\Models\{Business,Branch,Customer,CustomerPayment,DebtAllocation,Device,Plan,User,Product,Sale,SaleDebt,SalePayment,StockMovement,TillCashEntry,PersonalAccessToken};
use App\Services\{StaffRoleService,StockService,TillService,SaleService};
use App\Tenancy\TenantContext;
use Illuminate\Support\{Str,Facades\Concurrency};
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain'=>'bizismart.test']); $this->context=app(TenantContext::class);
    $plan=Plan::create(['code'=>'debt','name'=>'Debt','price_monthly'=>0,'limits'=>['tills'=>5],'features'=>[]]);
    foreach(['alpha','beta'] as $label) {
        $shop=Business::create(['name'=>$label,'subdomain'=>$label,'plan_id'=>$plan->id,'owner_phone'=>'0700000001']); $this->{$label}=$shop;
        $this->context->run($shop,function () use ($label) {
            app(StaffRoleService::class)->provision(); $branch=Branch::create(['name'=>'Main']); $this->{$label.'Branch'}=$branch;
            $device=Device::create(['device_uid'=>'debt-tests','name'=>'Debt tests','status'=>'approved']);
            foreach(['cashier','stockkeeper'] as $i=>$role) {
                $actor=User::create(['name'=>$role,'phone'=>'071000000'.$i,'pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$branch->id]); app(StaffRoleService::class)->assign($actor,$role);
                $token=$actor->createToken('debt-tests',['*'],now()->addDay()); $token->accessToken->device_id=$device->id;$token->accessToken->save();$actor->withAccessToken($token->accessToken);
                $this->{$label.ucfirst($role)}=$actor; $this->{$label.ucfirst($role).'Token'}=$token->plainTextToken;
            }
            $this->{$label.'Till'}=app(TillService::class)->open($this->{$label.'Cashier'},['operation_id'=>(string)Str::uuid(),'branch_id'=>$branch->publicId(),'opening_float'=>0])['till'];
            $p=Product::create(['name'=>'Rice bags','quantity_type'=>'counted','unit'=>'bag','selling_price'=>10000,'buying_price'=>100,'reorder_level_atoms'=>0,'is_active'=>true]);$this->{$label.'Product'}=$p;
            app(StockService::class)->receive($this->{$label.'Stockkeeper'},$p,['operation_id'=>(string)Str::uuid(),'batch_number'=>'OPENING','qty'=>'100','cost_price'=>100,'expiry_date'=>null]);
            $this->{$label.'Customer'}=Customer::create(['name'=>'Customer '.$label,'phone'=>'0712222222','credit_limit'=>50000])->fresh();
        });
    }
    $this->withToken($this->alphaCashierToken);
});
afterEach(function () { $this->context->clear();$this->travelBack(); });
function debtUrl(string $path=''):string {return 'http://alpha.bizismart.test/api/v1/customers'.$path;}
function debtSale($test,int $credit=10000,string $qty='1'):array {
    $input=['branch_id'=>$test->alphaBranch->publicId(),'till_id'=>$test->alphaTill['id'],'customer_id'=>$test->alphaCustomer->publicId(),'credit_amount'=>$credit,
        'items'=>[['product_id'=>$test->alphaProduct->publicId(),'qty'=>$qty]]];
    $review=$test->postJson('http://alpha.bizismart.test/api/v1/sales/preview',$input)->assertOk()->json('data');
    return $test->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('http://alpha.bizismart.test/api/v1/sales',[...$input,'cash_tendered'=>$review['cash_allocated'],'review_hash'=>$review['review_hash']])->assertCreated()->json('data');
}
function debtPayment($test,array $sales,int $amount):array {
    $body=['till_id'=>$test->alphaTill['id'],'method'=>'cash','amount'=>$amount,'allocations'=>$sales];
    $review=$test->postJson(debtUrl('/'.$test->alphaCustomer->publicId().'/payments/preview'),$body)->assertOk()->json('data');
    return [...$body,'review_hash'=>$review['review_hash']];
}

function creditSaleProcess(int $business,int $actorId,int $tokenId,string $key,array $body): Closure {
    return static function() use($business,$actorId,$tokenId,$key,$body) {
        if(!app()->environment('testing')||!str_ends_with(config('database.connections.mysql.database'),'_test'))throw new LogicException('Test database required.');
        app(TenantContext::class)->set(Business::findOrFail($business));
        $actor=User::findOrFail($actorId)->withAccessToken(PersonalAccessToken::findOrFail($tokenId));
        try{return app(SaleService::class)->complete($actor,$body,$key)['sale']->publicId();}
        catch(\App\Exceptions\ApiException $e){return $e->errorCode;}
    };
}
function collectionProcess(int $business,int $actorId,int $tokenId,int $customerId,string $key,array $input): Closure {
    return static function() use($business,$actorId,$tokenId,$customerId,$key,$input) {
        if(!app()->environment('testing')||!str_ends_with(config('database.connections.mysql.database'),'_test'))throw new LogicException('Test database required.');
        app(TenantContext::class)->set(Business::findOrFail($business));
        $actor=User::findOrFail($actorId)->withAccessToken(PersonalAccessToken::findOrFail($tokenId));
        try{return app(\App\Services\DebtService::class)->collect($actor,Customer::findOrFail($customerId),$input,$key)['payment']->publicId();}
        catch(\App\Exceptions\ApiException $e){return $e->errorCode;}
    };
}

it('creates encrypted tenant contacts with canonical uniqueness and protected balances',function () {
    $created=$this->postJson(debtUrl(),['name'=>'Asha','phone'=>'0620000000','credit_limit'=>20000])->assertCreated()->assertHeader('Cache-Control','no-store, private')
        ->assertJsonPath('data.outstanding_balance',0)->assertJsonPath('data.phone','255620000000')->assertJsonMissingPath('data.business_id')->json('data');
    $this->postJson(debtUrl(),['name'=>'Duplicate','phone'=>'+255620000000','credit_limit'=>0])->assertConflict()->assertJsonPath('error.code','DUPLICATE_CUSTOMER_PHONE');
    $this->putJson(debtUrl('/'.$created['id']),['credit_limit'=>0,'outstanding_balance'=>100])->assertUnprocessable();
    $this->getJson(debtUrl('?search=Asha&per_page=1'))->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.customers.0.id',$created['id']);
    $this->getJson(debtUrl('?search=0620000000'))->assertOk()->assertJsonPath('data.customers.0.id',$created['id']);
    $this->context->run($this->alpha,function () { $c=Customer::where('phone_lookup',Customer::phoneLookup('0620000000'))->first();expect($c->getRawOriginal('name'))->not->toBe('Asha');expect($c->getRawOriginal('phone'))->not->toBe('255620000000'); });
    $this->withToken($this->betaCashierToken)->getJson(str_replace('alpha.','beta.',debtUrl('/'.$created['id'])))->assertNotFound();
    $this->withToken($this->alphaStockkeeperToken)->getJson(debtUrl())->assertForbidden();
});
it('enforces credit limits while preserving full-credit and split cash journals',function () {
    debtSale($this,30000,'3');
    $input=['branch_id'=>$this->alphaBranch->publicId(),'till_id'=>$this->alphaTill['id'],'customer_id'=>$this->alphaCustomer->publicId(),'credit_amount'=>25000,'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'3']]];
    $this->postJson('http://alpha.bizismart.test/api/v1/sales/preview',$input)->assertStatus(400)->assertJsonPath('error.code','CREDIT_LIMIT_EXCEEDED');
    $sale=debtSale($this,20000,'3');expect($sale['change_given'])->toBe(0);expect(array_column($sale['payments'],'amount'))->toBe([10000,20000]);
    $this->getJson(debtUrl('/'.$this->alphaCustomer->publicId().'/debt'))->assertOk()->assertJsonPath('data.outstanding_balance',50000);
    $this->putJson(debtUrl('/'.$this->alphaCustomer->publicId()),['credit_limit'=>1000])->assertOk()->assertJsonPath('data.outstanding_balance',50000);
    $this->context->run($this->alpha,function () {expect(TillCashEntry::count())->toBe(1);expect(SaleDebt::sum('remaining_amount'))->toBe('50000');expect(app(TillService::class)->current($this->alphaCashier)['till']['expected_cash'])->toBe(10000);});
});
it('settles only staff-selected invoices and adds cash once with immutable replays',function () {
    $older=debtSale($this);$newer=debtSale($this);
    $body=debtPayment($this,[['sale_id'=>$newer['id'],'amount'=>5000]],5000);$key=(string)Str::uuid();$path='/'.$this->alphaCustomer->publicId().'/payments';
    $id=$this->withHeader('Idempotency-Key',$key)->postJson(debtUrl($path),$body)->assertCreated()->assertJsonPath('data.amount',5000)->json('data.id');
    $this->postJson(debtUrl($path),$body)->assertOk()->assertJsonPath('data.id',$id)->assertJsonPath('data.replayed',true);
    $this->postJson(debtUrl($path),[...$body,'amount'=>4999])->assertConflict()->assertJsonPath('error.code','OPERATION_ID_REUSED');
    $this->context->run($this->alpha,function () use ($older,$newer) {expect(CustomerPayment::count())->toBe(1);expect(DebtAllocation::count())->toBe(1);expect(Customer::find($this->alphaCustomer->id)->outstanding_balance)->toBe(15000);
        foreach([$older['id']=>10000,$newer['id']=>5000] as $id=>$balance){$sale=(new Sale)->resolveRouteBinding($id);expect(SaleDebt::where('sale_id',$sale->id)->first()->remaining_amount)->toBe($balance);}
        expect(app(TillService::class)->current($this->alphaCashier)['till']['expected_cash'])->toBe(5000);
    });
});
it('rejects overpayments mismatched allocations and other-customer invoices without mutations',function () {
    $sale=debtSale($this);$path='/'.$this->alphaCustomer->publicId().'/payments/preview';
    $body=['till_id'=>$this->alphaTill['id'],'method'=>'cash','amount'=>11000,'allocations'=>[['sale_id'=>$sale['id'],'amount'=>11000]]];
    $this->postJson(debtUrl($path),$body)->assertStatus(400)->assertJsonPath('error.code','OVERPAYMENT');
    $this->postJson(debtUrl($path),[...$body,'amount'=>5000,'allocations'=>[['sale_id'=>$sale['id'],'amount'=>4000]]])->assertStatus(400)->assertJsonPath('error.code','PAYMENT_MISMATCH');
    $other=$this->context->run($this->alpha,fn()=>Customer::create(['name'=>'Other','credit_limit'=>10000])->fresh());
    $original=$this->alphaCustomer; $this->alphaCustomer=$other; debtSale($this); $this->alphaCustomer=$original;
    $this->postJson(debtUrl('/'.$other->publicId().'/payments/preview'),[...$body,'amount'=>1,'allocations'=>[['sale_id'=>$sale['id'],'amount'=>1]]])->assertNotFound()->assertJsonPath('error.code','NOT_FOUND');
    $mobile=[...$body,'amount'=>5000,'method'=>'mpesa','allocations'=>[['sale_id'=>$sale['id'],'amount'=>5000]]];
    $review=$this->postJson(debtUrl($path),$mobile)->assertOk()->assertJsonPath('data.method','mpesa')->json('data');
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(debtUrl('/'.$this->alphaCustomer->publicId().'/payments'),[...$mobile,'review_hash'=>$review['review_hash']])->assertNotFound()->assertJsonPath('error.code','NOT_FOUND');
    $this->context->run($this->alpha,fn()=>expect(CustomerPayment::count())->toBe(0));
});
it('requires fresh collection reviews and rolls journals balances and cash back together',function () {
    $sale=debtSale($this);$body=debtPayment($this,[['sale_id'=>$sale['id'],'amount'=>1000]],1000);$path='/'.$this->alphaCustomer->publicId().'/payments';
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(debtUrl($path),$body)->assertCreated();
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(debtUrl($path),$body)->assertConflict()->assertJsonPath('error.code','DEBT_CHANGED');
    $fresh=debtPayment($this,[['sale_id'=>$sale['id'],'amount'=>2000]],2000);
    $this->mock(TillService::class,fn($mock)=>$mock->shouldReceive('recordCash')->once()->andThrow(new RuntimeException('Injected cash failure')));
    $this->app['router']->getRoutes()->getByName('debts.collect')->flushController();
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(debtUrl($path),$fresh)->assertStatus(500);
    $this->context->run($this->alpha,function () {expect(Customer::find($this->alphaCustomer->id)->outstanding_balance)->toBe(9000);expect(CustomerPayment::count())->toBe(1);expect(DebtAllocation::sum('amount'))->toBe('1000');expect(TillCashEntry::sum('amount'))->toBe('1000');});
});
it('keeps aging based on unpaid sale dates across four buckets',function () {
    foreach([10,45,75,120] as $days){$sale=debtSale($this);$this->context->run($this->alpha,fn()=>(new Sale)->resolveRouteBindingQuery(Sale::query(),$sale['id'])->update(['created_at'=>now()->subDays($days)]));}
    $this->getJson(debtUrl('/'.$this->alphaCustomer->publicId().'/debt'))->assertOk()->assertJsonPath('data.aging',['0_30'=>10000,'31_60'=>10000,'61_90'=>10000,'90_plus'=>10000]);
});
it('serializes competing credit sales on independent MySQL connections',function () {
    $this->context->run($this->alpha,fn()=>$this->alphaCustomer->update(['credit_limit'=>10000]));
    $input=['branch_id'=>$this->alphaBranch->publicId(),'till_id'=>$this->alphaTill['id'],'customer_id'=>$this->alphaCustomer->publicId(),'credit_amount'=>10000,'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1']]];
    $review=$this->postJson('http://alpha.bizismart.test/api/v1/sales/preview',$input)->assertOk()->json('data');$body=[...$input,'cash_tendered'=>0,'review_hash'=>$review['review_hash']];
    \Illuminate\Support\Facades\DB::commit(); \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated=false; $tasks=[];
    foreach([1,2] as $i) {
        $tasks[]=creditSaleProcess($this->alpha->id,$this->alphaCashier->id,$this->alphaCashier->currentAccessToken()->id,(string)Str::uuid(),$body);
    }
    $results=Concurrency::driver('process')->run($tasks);expect(count(array_filter($results,fn($v)=>str_starts_with($v,'sale_'))))->toBe(1);expect($results)->toContain('CREDIT_LIMIT_EXCEEDED');
    $this->context->run($this->alpha,function(){expect(Sale::count())->toBe(1);expect(Customer::find($this->alphaCustomer->id)->outstanding_balance)->toBe(10000);});
});

it('serializes duplicate and competing invoice collections on independent MySQL connections',function () {
    $sale=debtSale($this); $body=debtPayment($this,[['sale_id'=>$sale['id'],'amount'=>4000]],4000);
    \Illuminate\Support\Facades\DB::commit(); \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated=false;
    $business=$this->alpha->id; $actorId=$this->alphaCashier->id; $tokenId=$this->alphaCashier->currentAccessToken()->id; $customerId=$this->alphaCustomer->id;
    $key=(string)Str::uuid(); $results=Concurrency::driver('process')->run([collectionProcess($business,$actorId,$tokenId,$customerId,$key,$body),collectionProcess($business,$actorId,$tokenId,$customerId,$key,$body)]);
    expect($results[0])->toStartWith('cpay_')->toBe($results[1]);
    $this->context->run($this->alpha,function(){expect(CustomerPayment::count())->toBe(1);expect(DebtAllocation::count())->toBe(1);expect(Customer::find($this->alphaCustomer->id)->outstanding_balance)->toBe(6000);});
    $body=debtPayment($this,[['sale_id'=>$sale['id'],'amount'=>4000]],4000);
    $results=Concurrency::driver('process')->run([collectionProcess($business,$actorId,$tokenId,$customerId,(string)Str::uuid(),$body),collectionProcess($business,$actorId,$tokenId,$customerId,(string)Str::uuid(),$body)]);
    expect(count(array_filter($results,fn($v)=>str_starts_with($v,'cpay_'))))->toBe(1);expect($results)->toContain('OVERPAYMENT');
    $this->context->run($this->alpha,function(){expect(CustomerPayment::count())->toBe(2);expect(DebtAllocation::count())->toBe(2);
        expect(Customer::find($this->alphaCustomer->id)->outstanding_balance)->toBe(2000);expect(SaleDebt::sum('remaining_amount'))->toBe('2000');
        expect(TillCashEntry::sum('amount'))->toBe('8000');});
});
