<?php
use App\Models\{Business,Branch,Device,Plan,User,Product,ProductBatch,StockMovement,SupplierInvoice,SupplierReturn,SupplierCredit,SupplierPayment,SupplierAllocation,SupplierOperation,TillCashEntry};
use App\Services\{StaffRoleService,StockService,TillService};
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);
beforeEach(function(){
    config(['tenancy.domain'=>'bizismart.test']);$this->context=app(TenantContext::class);
    $plan=Plan::create(['code'=>'supplier-tests','name'=>'Suppliers','price_monthly'=>0,'limits'=>['tills'=>5],'features'=>[]]);
    foreach(['alpha','beta'] as $label){$shop=Business::create(['name'=>$label,'subdomain'=>$label,'plan_id'=>$plan->id,'owner_phone'=>'0700000001']);$this->{$label}=$shop;
        $this->context->run($shop,function()use($label){app(StaffRoleService::class)->provision();$branch=Branch::create(['name'=>'Main']);$this->{$label.'Branch'}=$branch;
            $device=Device::create(['device_uid'=>'supplier-tests','name'=>'Supplier tests','status'=>'approved']);$this->{$label.'Device'}=$device;
            foreach(['owner','stockkeeper','manager','cashier'] as $i=>$role){$actor=User::create(['name'=>$role,'phone'=>'071000000'.$i,'pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$branch->id]);app(StaffRoleService::class)->assign($actor,$role);
                if($role==='owner'){$actor->totp_secret='JBSWY3DPEHPK3PXP';$actor->totp_confirmed_at=now();$actor->save();}
                $token=$actor->createToken('supplier-tests',['*'],now()->addDay());$token->accessToken->device_id=$device->id;$token->accessToken->save();$actor->withAccessToken($token->accessToken);$this->{$label.ucfirst($role)}=$actor;$this->{$label.ucfirst($role).'Token'}=$token->plainTextToken;}
            $this->{$label.'Product'}=Product::create(['name'=>'Rice','quantity_type'=>'weight','unit'=>'kg','selling_price'=>4000,'buying_price'=>2500,'reorder_level_atoms'=>0,'is_active'=>true]);
            $this->{$label.'Till'}=app(TillService::class)->open($this->{$label.'Owner'},['operation_id'=>(string)Str::uuid(),'branch_id'=>$branch->publicId(),'opening_float'=>20000])['till'];
        });
    }
    $this->withToken($this->alphaOwnerToken);
    $this->supplier=supplierWrite($this,'supplier',['name'=>'Rice supplier','phone'=>'0624643714','address'=>'Private supplier address','is_active'=>true])['supplier'];
});
afterEach(function(){$this->context->clear();$this->travelBack();});
function supplierUrl(string $suffix=''):string{return 'http://alpha.bizismart.test/api/v1/suppliers'.$suffix;}
function supplierWrite($t,string $action,array $body,?string $key=null):array{$review=$t->postJson(supplierUrl('/actions/'.$action.'/preview'),$body)->assertOk()->json('data');return $t->withHeader('Idempotency-Key',$key??(string)Str::uuid())->postJson(supplierUrl('/actions/'.$action),[...$body,'review_hash'=>$review['review_hash']])->assertOk()->json('data');}
function supplierDelivery($t,array $extra=[]):array{return ['supplier_id'=>$t->supplier['id'],'invoice_reference'=>'INV-1','invoice_date'=>now('Africa/Dar_es_Salaam')->toDateString(),'lines'=>[['product_id'=>$t->alphaProduct->publicId(),'qty'=>'5','cost_price'=>2500,'batch_number'=>'RICE','expiry_date'=>null]],...$extra];}
function supplierReceived($t,array $extra=[]):array{return supplierWrite($t,'receive',supplierDelivery($t,$extra))['invoice'];}
it('keeps orders separate from stock and payable and receives an issued order exactly once',function(){
    $order=supplierWrite($this,'order',['supplier_id'=>$this->supplier['id'],'status'=>'draft','lines'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'5','cost_price'=>2500]]])['order'];
    $this->context->run($this->alpha,function(){expect(ProductBatch::count())->toBe(0);expect(SupplierInvoice::count())->toBe(0);});
    $order=supplierWrite($this,'order',['supplier_id'=>$this->supplier['id'],'order_id'=>$order['id'],'expected_revision'=>$order['revision'],'status'=>'issued','lines'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'5','cost_price'=>2500]]])['order'];
    $this->withToken($this->alphaStockkeeperToken);$body=supplierDelivery($this,['order_id'=>$order['id']]);$review=$this->postJson(supplierUrl('/actions/receive/preview'),$body)->assertOk()->assertJsonPath('data.total',12500)->json('data');$key=(string)Str::uuid();
    $saved=$this->withHeader('Idempotency-Key',$key)->postJson(supplierUrl('/actions/receive'),[...$body,'review_hash'=>$review['review_hash']])->assertOk()->json('data');
    $this->postJson(supplierUrl('/actions/receive'),[...$body,'review_hash'=>$review['review_hash']])->assertOk()->assertJsonPath('data.invoice.id',$saved['invoice']['id'])->assertJsonPath('data.replayed',true);
    $this->withToken($this->alphaOwnerToken)->getJson(supplierUrl())->assertOk()->assertJsonPath('data.suppliers.0.payable',12500);
    $this->context->run($this->alpha,function(){expect(ProductBatch::first()->qty_remaining_atoms)->toBe(5000);expect(StockMovement::where('type','receive')->count())->toBe(1);expect(TillCashEntry::count())->toBe(0);});
});
it('denies financial reads to managers and stockkeepers and requires issued goods for keeper receiving',function(){
    $this->withToken($this->alphaManagerToken)->getJson(supplierUrl())->assertForbidden();
    $this->withToken($this->alphaStockkeeperToken)->getJson(supplierUrl())->assertForbidden();
    $this->postJson(supplierUrl('/actions/receive/preview'),supplierDelivery($this))->assertForbidden();
    $this->postJson(supplierUrl('/actions/payment/preview'),['supplier_id'=>$this->supplier['id'],'source'=>'bank','reference'=>'PAY-1','paid_on'=>now()->toDateString(),'completed'=>true,'allocations'=>[]])->assertForbidden();
});
it('rejects duplicate invoices wrong tenants changed reviews and rolls back a later receiving failure',function(){
    $body=supplierDelivery($this);$review=$this->postJson(supplierUrl('/actions/receive/preview'),$body)->assertOk()->json('data');
    $this->context->run($this->alpha,fn()=> $this->alphaProduct->update(['name'=>'Changed product']));
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(supplierUrl('/actions/receive'),[...$body,'review_hash'=>$review['review_hash']])->assertConflict()->assertJsonPath('error.code','SUPPLIER_CHANGED');
    supplierReceived($this);$this->postJson(supplierUrl('/actions/receive/preview'),$body)->assertConflict()->assertJsonPath('error.code','INVOICE_ALREADY_RECEIVED');
    $this->withToken($this->betaOwnerToken)->postJson(str_replace('alpha.','beta.',supplierUrl('/actions/receive/preview')),$body)->assertNotFound();
    $this->withToken($this->alphaOwnerToken);$body=supplierDelivery($this,['invoice_reference'=>'INV-ROLLBACK','lines'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1','cost_price'=>2500,'batch_number'=>'OK'],['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1','cost_price'=>2500,'batch_number'=>'FAIL','expiry_date'=>'2000-01-01']]]);
    $review=$this->postJson(supplierUrl('/actions/receive/preview'),$body)->assertOk()->json('data');
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(supplierUrl('/actions/receive'),[...$body,'review_hash'=>$review['review_hash']])->assertStatus(400)->assertJsonPath('error.code','EXPIRED_STOCK');
    $this->context->run($this->alpha,function(){expect(SupplierInvoice::count())->toBe(1);expect(ProductBatch::sum('qty_remaining_atoms'))->toBe('5000');expect(StockMovement::where('type','receive')->count())->toBe(1);});
});
it('settles explicitly selected invoices once and records external payment without drawer or network effects',function(){
    $first=supplierReceived($this);$second=supplierReceived($this,['invoice_reference'=>'INV-2']);
    $body=['supplier_id'=>$this->supplier['id'],'source'=>'mobile','reference'=>'EXTERNAL-1','paid_on'=>now('Africa/Dar_es_Salaam')->toDateString(),'completed'=>true,'allocations'=>[['invoice_id'=>$second['id'],'amount'=>3000]]];
    $review=$this->postJson(supplierUrl('/actions/payment/preview'),$body)->assertOk()->assertJsonPath('data.amount',3000)->json('data');$key=(string)Str::uuid();$commit=[...$body,'review_hash'=>$review['review_hash']];
    $this->withHeader('Idempotency-Key',$key)->postJson(supplierUrl('/actions/payment'),$commit)->assertOk()->assertJsonPath('data.external_transfer_executed',false);
    $this->postJson(supplierUrl('/actions/payment'),$commit)->assertOk()->assertJsonPath('data.replayed',true);
    $this->context->run($this->alpha,function()use($first,$second){expect((new SupplierInvoice)->resolveRouteBinding($first['id'])->remaining_amount)->toBe(12500);expect((new SupplierInvoice)->resolveRouteBinding($second['id'])->remaining_amount)->toBe(9500);expect(SupplierPayment::count())->toBe(1);expect(SupplierAllocation::count())->toBe(1);expect(TillCashEntry::count())->toBe(0);});
    $this->postJson(supplierUrl('/actions/payment/preview'),$body)->assertConflict()->assertJsonPath('error.code','REFERENCE_ALREADY_RECORDED');
});
it('returns exact original goods without reducing payable until an issued note and retains excess credits',function(){
    $invoice=supplierReceived($this);$return=supplierWrite($this,'return',['supplier_id'=>$this->supplier['id'],'invoice_id'=>$invoice['id'],'reason'=>'Supplier accepted damaged rice','lines'=>[['line_index'=>0,'qty'=>'1.001']]])['return'];
    expect($return['expected_credit'])->toBe(2503);
    $this->context->run($this->alpha,function(){expect(ProductBatch::first()->qty_remaining_atoms)->toBe(3999);expect(SupplierInvoice::first()->remaining_amount)->toBe(12500);$movement=StockMovement::where('type','supplier_return')->first();expect($movement->qty_change_atoms)->toBe(-1001);expect($movement->receipt['qty_returned'])->toBe('1.001');expect($movement->receipt['qty_after'])->toBe('3.999');expect($movement->receipt['cost_price'])->toBe(2500);});
    $credit=supplierWrite($this,'credit',['supplier_id'=>$this->supplier['id'],'return_id'=>$return['id'],'reference'=>'NOTE-1','amount'=>15000,'completed'=>true,'allocations'=>[['invoice_id'=>$invoice['id'],'amount'=>12500]]])['credit'];
    expect($credit['remaining_amount'])->toBe(2500);
    $this->context->run($this->alpha,function(){expect(SupplierInvoice::first()->remaining_amount)->toBe(0);expect(SupplierCredit::count())->toBe(1);});
    $second=supplierReceived($this,['invoice_reference'=>'INV-NEW']);supplierWrite($this,'apply_credit',['supplier_id'=>$this->supplier['id'],'credit_id'=>$credit['id'],'allocations'=>[['invoice_id'=>$second['id'],'amount'=>2500]]]);
    $this->context->run($this->alpha,function()use($second){expect(SupplierCredit::first()->remaining_amount)->toBe(0);expect((new SupplierInvoice)->resolveRouteBinding($second['id'])->remaining_amount)->toBe(10000);});
});
it('checks own till cash and rolls back payment allocation if the drawer hook fails',function(){
    $invoice=supplierReceived($this);$body=['supplier_id'=>$this->supplier['id'],'source'=>'till','till_id'=>$this->alphaTill['id'],'reference'=>'CASH-1','paid_on'=>now('Africa/Dar_es_Salaam')->toDateString(),'allocations'=>[['invoice_id'=>$invoice['id'],'amount'=>12500]]];
    $review=$this->postJson(supplierUrl('/actions/payment/preview'),$body)->assertOk()->json('data');
    $mock=\Mockery::mock(TillService::class,[app(TenantContext::class),app(\App\Services\TillAccessService::class),app(\App\Services\TillCashService::class)])->makePartial();
    $mock->shouldReceive('recordCash')->once()->andThrow(new RuntimeException('Injected drawer failure'));app()->instance(TillService::class,$mock);
    // Preview also reads the authoritative drawer. Preserve that real path when injecting only the write hook.
    $this->app->forgetInstance(\App\Services\SupplierService::class);app('router')->getRoutes()->getByName('suppliers.commit')->flushController();
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(supplierUrl('/actions/payment'),[...$body,'review_hash'=>$review['review_hash']])->assertStatus(500);
    $this->context->run($this->alpha,function(){expect(SupplierPayment::count())->toBe(0);expect(SupplierAllocation::count())->toBe(0);expect(SupplierInvoice::first()->remaining_amount)->toBe(12500);expect(TillCashEntry::count())->toBe(0);});
});
