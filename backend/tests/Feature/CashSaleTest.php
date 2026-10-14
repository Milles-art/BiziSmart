<?php
use App\Models\{Business,Branch,Device,Plan,User,Product,ProductBatch,StockMovement,Sale,SaleItem,SalePayment,TillCashEntry,TillSession,PersonalAccessToken};
use App\Services\{StaffRoleService,StockService,TillService,SaleService};
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\{DB,Concurrency};
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);
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
    $this->input=['branch_id'=>$this->alphaBranch->publicId(),'till_id'=>$this->alphaTill['id'],'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1','packaging_id'=>null]]];
});
afterEach(function () { $this->context->clear(); $this->travelBack(); TillCashEntry::flushEventListeners(); \Illuminate\Database\Eloquent\Model::clearBootedModels(); });
function saleTestUrl(string $path=''): string { return 'http://alpha.bizismart.test/api/v1/sales'.$path; }
function cashSaleBody($test,array $input=[]): array { $data=$input ?: $test->input; $review=$test->postJson(saleTestUrl('/preview'),$data)->assertOk()->json('data'); return [...$data,'review_hash'=>$review['review_hash'],'cash_tendered'=>max(10000,$review['total'])]; }
function saleProcess(int $businessId,int $actorId,int $tokenId,array $body,string $key): Closure {
    return static function () use ($businessId,$actorId,$tokenId,$body,$key) {
        if (!app()->environment('testing') || !str_ends_with(config('database.connections.mysql.database'),'_test')) throw new LogicException('Dedicated test database required.');
        app(TenantContext::class)->set(Business::findOrFail($businessId)); $actor=User::findOrFail($actorId)->withAccessToken(PersonalAccessToken::findOrFail($tokenId));
        try { $r=app(SaleService::class)->complete($actor,$body,$key); return ['id'=>$r['sale']->publicId(),'replayed'=>$r['replayed']]; }
        catch (\App\Exceptions\ApiException $e) { return ['error'=>$e->errorCode]; }
    };
}
it('commits a cash receipt exact VAT stock and net till cash once',function () {
    $body=cashSaleBody($this); $key=(string)Str::uuid();
    $sale=$this->withHeader('Idempotency-Key',$key)->postJson(saleTestUrl(),$body)->assertCreated()->assertHeader('Cache-Control','no-store, private')
        ->assertJsonPath('data.total',8500)->assertJsonPath('data.vat_amount',1297)->assertJsonPath('data.change_given',1500)->assertJsonPath('data.payments.0.amount',8500)->assertJsonPath('data.lines.0.qty','1')
        ->assertJsonMissingPath('data.business_id')->assertJsonMissingPath('data.device_id')->assertJsonMissingPath('data.lines.0.buying_price')->json('data');
    $this->postJson(saleTestUrl(),$body)->assertOk()->assertJsonPath('data.id',$sale['id'])->assertJsonPath('data.replayed',true);
    $this->getJson(saleTestUrl('/'.$sale['id']))->assertOk()->assertJsonPath('data.receipt_number',$sale['receipt_number']);
    $this->context->run($this->alpha,function () { expect(Sale::count())->toBe(1); expect(SaleItem::count())->toBe(1); expect(SalePayment::count())->toBe(1); expect(TillCashEntry::count())->toBe(1); expect(ProductBatch::sum('qty_remaining_atoms'))->toBe('19'); expect(app(TillService::class)->current($this->alphaCashier)['till']['expected_cash'])->toBe(28500); });
    $this->postJson(saleTestUrl(),[...$body,'cash_tendered'=>10001])->assertConflict()->assertJsonPath('error.code','OPERATION_ID_REUSED');
});
it('allocates dated stock before undated skipping expired quarantined and recalled batches',function () {
    $this->context->run($this->alpha,function () {
        foreach ([['EARLY',10,10],['LATER',20,20],['EXPIRED',50,1],['HELD',50,1],['RECALLED',50,1]] as [$name,$qty,$days]) {
            $b=app(StockService::class)->receive($this->alphaStockkeeper,$this->alphaProduct,['operation_id'=>(string)Str::uuid(),'batch_number'=>$name,'qty'=>(string)$qty,'cost_price'=>100,'expiry_date'=>now('Africa/Dar_es_Salaam')->addDays($days)->toDateString()])['batch'];
            if ($name==='EXPIRED') $b->update(['expiry_date'=>now('Africa/Dar_es_Salaam')->subDay()->toDateString()]);
            if ($name==='HELD') $b->update(['is_quarantined'=>true]); if ($name==='RECALLED') $b->update(['is_recalled'=>true]);
        }
    });
    $body=cashSaleBody($this,[...$this->input,'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'35']]]);
    $sale=$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(saleTestUrl(),$body)->assertCreated()->json('data');
    expect(array_column($sale['items'],'base_qty'))->toBe(['10','20','5']);
    $this->context->run($this->alpha,function () { expect(ProductBatch::where('batch_number','EARLY')->first()->qty_remaining_atoms)->toBe(0); expect(ProductBatch::where('batch_number','UNDATED')->first()->qty_remaining_atoms)->toBe(15); foreach (['EXPIRED','HELD','RECALLED'] as $n) expect(ProductBatch::where('batch_number',$n)->first()->qty_remaining_atoms)->toBe(50); });
});
it('rounds a weighted logical line once before batch splitting and uses independent pack prices',function () {
    $this->context->run($this->alpha,function () {
        $p=Product::create(['name'=>'Rice','quantity_type'=>'weight','unit'=>'kg','selling_price'=>500,'buying_price'=>100,'reorder_level_atoms'=>0,'is_active'=>true]); $this->weighted=$p;
        foreach (['0.001','0.001'] as $q) app(StockService::class)->receive($this->alphaStockkeeper,$p,['operation_id'=>(string)Str::uuid(),'batch_number'=>'RICE','qty'=>$q,'cost_price'=>100,'expiry_date'=>null]);
    });
    $body=cashSaleBody($this,[...$this->input,'items'=>[['product_id'=>$this->weighted->publicId(),'qty'=>'0.002']]]);
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(saleTestUrl(),$body)->assertCreated()->assertJsonPath('data.total',1)->assertJsonCount(2,'data.items')->assertJsonPath('data.lines.0.base_qty','0.002');
    $pack=$this->context->run($this->alpha,fn()=>app(\App\Services\PackagingService::class)->save($this->alphaStockkeeper,$this->alphaProduct,['name'=>'Carton','base_qty'=>'5','selling_price'=>9000,'is_active'=>true]));
    $body=cashSaleBody($this,[...$this->input,'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'2','packaging_id'=>$pack->publicId()]]]);
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(saleTestUrl(),$body)->assertCreated()->assertJsonPath('data.total',18000)->assertJsonPath('data.lines.0.base_qty','10')->assertJsonPath('data.lines.0.packaging_version',1);
});
it('rejects stale price reviews missing cash and overselling without partial writes',function () {
    $body=cashSaleBody($this); $key=(string)Str::uuid(); $this->withHeader('Idempotency-Key',$key);
    $this->postJson(saleTestUrl(),[...$body,'cash_tendered'=>8499])->assertStatus(400)->assertJsonPath('error.code','PAYMENT_MISMATCH');
    $this->context->run($this->alpha,fn()=>$this->alphaProduct->update(['selling_price'=>9000]));
    $this->postJson(saleTestUrl(),$body)->assertConflict()->assertJsonPath('error.code','SALE_CHANGED');
    $body=cashSaleBody($this,[...$this->input,'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'21']]]);
    $this->postJson(saleTestUrl(),$body)->assertStatus(400)->assertJsonPath('error.code','INSUFFICIENT_STOCK')->assertJsonPath('error.details.available_qty','20');
    $this->context->run($this->alpha,function () { expect(Sale::count())->toBe(0); expect(SaleItem::count())->toBe(0); expect(TillCashEntry::count())->toBe(0); expect(StockMovement::count())->toBe(1); expect(ProductBatch::sum('qty_remaining_atoms'))->toBe('20'); expect((int)$this->alpha->fresh()->sale_sequence)->toBe(0); });
});
it('rolls all stock allocations back when the cash journal fails',function () {
    $body=cashSaleBody($this);
    $this->mock(TillService::class, fn ($mock) => $mock->shouldReceive('recordCash')->once()->andThrow(new RuntimeException('Injected cash failure')));
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(saleTestUrl(),$body)->assertStatus(500);
    $this->context->run($this->alpha,function () { expect(Sale::count())->toBe(0); expect(SaleItem::count())->toBe(0); expect(SalePayment::count())->toBe(0); expect(StockMovement::count())->toBe(1); expect(ProductBatch::sum('qty_remaining_atoms'))->toBe('20'); });
});
it('replays a saved receipt after prices change product archives and till closes',function () {
    $body=cashSaleBody($this); $this->withHeader('Idempotency-Key',(string)Str::uuid()); $id=$this->postJson(saleTestUrl(),$body)->assertCreated()->json('data.id');
    $this->context->run($this->alpha,function () {
        $this->alphaProduct->update(['selling_price'=>9000]); $this->alphaProduct->delete();
        $r=app(TillService::class)->preview($this->alphaCashier,['operation_id'=>(string)Str::uuid(),'till_id'=>$this->alphaTill['id'],'counted_cash'=>28500]);
        $commit=$r['snapshot']; unset($commit['branch_id']); app(TillService::class)->close($this->alphaCashier,$commit);
    });
    $this->postJson(saleTestUrl(),$body)->assertOk()->assertJsonPath('data.id',$id)->assertJsonPath('data.total',8500);
});
it('validates exact quantities inputs permissions devices and tenant ownership',function () {
    foreach (['1.1','0','01','1e3'] as $q) $this->postJson(saleTestUrl('/preview'),[...$this->input,'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>$q]]])->assertUnprocessable();
    foreach (['business_id'=>$this->alpha->id,'total'=>1,'discount'=>1,'payments'=>[['method'=>'mpesa','amount'=>8500]]] as $k=>$v) $this->postJson(saleTestUrl('/preview'),[...$this->input,$k=>$v])->assertUnprocessable();
    $body=cashSaleBody($this); $this->postJson(saleTestUrl(),$body)->assertUnprocessable();
    $this->withToken($this->alphaManagerToken)->postJson(saleTestUrl('/preview'),$this->input)->assertForbidden();
    $this->withToken($this->alphaStockkeeperToken)->postJson(saleTestUrl('/preview'),$this->input)->assertForbidden();
    $this->withToken($this->betaCashierToken)->postJson(str_replace('alpha.','beta.',saleTestUrl('/preview')),[...$this->input,'branch_id'=>$this->betaBranch->publicId(),'till_id'=>$this->betaTill['id']])->assertNotFound();
    $this->withToken($this->alphaCashierToken)->postJson(saleTestUrl('/preview'),[...$this->input,'till_id'=>$this->betaTill['id']])->assertStatus(400);
    $this->context->run($this->alpha,fn()=>$this->alphaDevice->update(['status'=>'blocked'])); $this->postJson(saleTestUrl('/preview'),$this->input)->assertUnauthorized();
});
it('restricts saved receipts and rejects inactive products and fractional packs', function () {
    $pack = $this->context->run($this->alpha, fn () => app(\App\Services\PackagingService::class)->save(
        $this->alphaStockkeeper, $this->alphaProduct,
        ['name'=>'Case', 'base_qty'=>'5', 'selling_price'=>9000, 'is_active'=>true]
    ));
    $this->postJson(saleTestUrl('/preview'), [...$this->input, 'items'=>[
        ['product_id'=>$this->alphaProduct->publicId(), 'qty'=>'1.1', 'packaging_id'=>$pack->publicId()]
    ]])->assertUnprocessable();

    $body = cashSaleBody($this);
    $sale = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(saleTestUrl(), $body)->assertCreated()->json('data.id');
    $this->withToken($this->alphaManagerToken)->getJson(saleTestUrl('/'.$sale))->assertForbidden();
    $this->withToken($this->betaCashierToken)->getJson(str_replace('alpha.', 'beta.', saleTestUrl('/'.$sale)))->assertNotFound();
    $this->withToken($this->betaCashierToken)->getJson(saleTestUrl('/'.$sale))->assertUnauthorized();
    $this->withToken($this->alphaCashierToken);

    $this->context->run($this->alpha, fn () => $this->alphaProduct->update(['is_active'=>false]));
    $this->postJson(saleTestUrl('/preview'), $this->input)->assertConflict()->assertJsonPath('error.code', 'PRODUCT_INACTIVE');
    $this->context->run($this->alpha, fn () => $this->alphaProduct->update(['is_active'=>true]));
    $this->context->run($this->alpha, fn () => app(\App\Services\PackagingService::class)->archive($this->alphaStockkeeper, $this->alphaProduct, $pack));
    $this->postJson(saleTestUrl('/preview'), [...$this->input, 'items'=>[
        ['product_id'=>$this->alphaProduct->publicId(), 'qty'=>'1', 'packaging_id'=>$pack->publicId()]
    ]])->assertConflict()->assertJsonPath('error.code', 'PACKAGING_VERSION_CHANGED');
});
it('serializes independent duplicate requests on real MySQL connections',function () {
    $body=cashSaleBody($this,[...$this->input,'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'20']]]);
    $key=(string)Str::uuid(); $job=saleProcess($this->alpha->id,$this->alphaCashier->id,$this->alphaCashier->currentAccessToken()->id,$body,$key);
    DB::commit(); \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated=false;
    $results=Concurrency::driver('process')->run([$job,$job]); expect($results[0]['id'])->toBe($results[1]['id']); expect(array_column($results,'replayed'))->toContain(true,false);
    $this->context->run($this->alpha,function () { expect(Sale::count())->toBe(1); expect(TillCashEntry::count())->toBe(1); expect((string)ProductBatch::sum('qty_remaining_atoms'))->toBe('0'); });
});
it('prevents two independent cashiers from both selling the last stock balance',function () {
    $body=cashSaleBody($this,[...$this->input,'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'20']]]);
    $till=$this->context->run($this->alpha,fn()=>app(TillService::class)->open($this->alphaManager,['operation_id'=>(string)Str::uuid(),'branch_id'=>$this->alphaBranch->publicId(),'opening_float'=>0])['till']);
    $this->withToken($this->alphaManagerToken); $otherBody=cashSaleBody($this,[...$this->input,'till_id'=>$till['id'],'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'20']]]);
    $jobs=[saleProcess($this->alpha->id,$this->alphaCashier->id,$this->alphaCashier->currentAccessToken()->id,$body,(string)Str::uuid()),
        saleProcess($this->alpha->id,$this->alphaManager->id,$this->alphaManager->currentAccessToken()->id,$otherBody,(string)Str::uuid())];
    DB::commit(); \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated=false;
    $results=Concurrency::driver('process')->run($jobs);
    expect(array_column($results,'error'))->toBe(['INSUFFICIENT_STOCK']); expect(array_column($results,'id'))->toHaveCount(1);
    $this->context->run($this->alpha,function () { expect(Sale::count())->toBe(1); expect(TillCashEntry::count())->toBe(1); expect((string)ProductBatch::sum('qty_remaining_atoms'))->toBe('0'); });
});
