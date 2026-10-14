<?php
use App\Models\{Business,Branch,Device,Plan,User,Supplier,SupplierInvoice,SupplierPayment,BankStatementLine,SupplierPaymentSchedule,Expense,ExpensePayment};
use App\Services\StaffRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);
beforeEach(function(){
 config(['tenancy.domain'=>'bizismart.test']);$this->context=app(TenantContext::class);
 $plan=Plan::create(['code'=>'bank-tests','name'=>'Banking','price_monthly'=>0,'limits'=>['tills'=>5],'features'=>[]]);
 foreach(['alpha','beta'] as $label){$shop=Business::create(['name'=>$label,'subdomain'=>$label,'plan_id'=>$plan->id,'owner_phone'=>'0700000001']);$this->{$label}=$shop;
  $this->context->run($shop,function()use($label){app(StaffRoleService::class)->provision();$branch=Branch::create(['name'=>'Main']);$device=Device::create(['device_uid'=>'bank-tests','name'=>'Bank tests','status'=>'approved']);
   foreach(['owner','manager'] as $i=>$role){$actor=User::create(['name'=>$role,'phone'=>'071000000'.$i,'pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$branch->id]);app(StaffRoleService::class)->assign($actor,$role);if($role==='owner'){$actor->totp_secret='JBSWY3DPEHPK3PXP';$actor->totp_confirmed_at=now();$actor->save();}$token=$actor->createToken('bank-tests',['*'],now()->addDay());$token->accessToken->device_id=$device->id;$token->accessToken->save();$this->{$label.ucfirst($role).'Token'}=$token->plainTextToken;$this->{$label.ucfirst($role)}=$actor;}
   $supplier=Supplier::create(['name'=>'Rice supplier']);$this->{$label.'Invoice'}=SupplierInvoice::create(['supplier_id'=>$supplier->id,'actor_id'=>$this->{$label.'Owner'}->id,'invoice_reference'=>'INV-1','invoice_hash'=>hash('sha256','INV-1'),'original_amount'=>9000,'remaining_amount'=>9000,'invoice_date'=>'2026-10-10','lines'=>[['product_id'=>'prod_'.Str::ulid(),'name'=>'Rice','unit'=>'kg','quantity_type'=>'weight','cost_price'=>3000,'base_qty'=>'3']]]);
   $this->{$label.'Payment'}=SupplierPayment::create(['supplier_id'=>$supplier->id,'actor_id'=>$this->{$label.'Owner'}->id,'source'=>'bank','amount'=>3000,'reference'=>'BANK-PAY-1','reference_hash'=>hash('sha256','BANK-PAY-1'),'paid_on'=>'2026-10-12']);
  });
 }$this->withToken($this->alphaOwnerToken);
});
afterEach(function(){$this->context->clear();});
function bankUrl(string $suffix=''):string{return 'http://alpha.bizismart.test/api/v1/banking'.$suffix;}
function bankInput(array $extra=[]):array{return ['operation_id'=>(string)Str::uuid(),'account'=>'Shop bank','booked_on'=>'2026-10-12','direction'=>'debit','amount'=>3000,'reference'=>'BANK-LINE-1',...$extra];}
it('records immutable exact statement evidence and matches a payment once without changing journals',function(){
 $body=bankInput();$line=$this->postJson(bankUrl('/statement'),$body)->assertOk()->assertJsonPath('data.external_transfer_executed',false)->json('data.line');
 $this->postJson(bankUrl('/statement'),$body)->assertOk()->assertJsonPath('data.replayed',true);
 $this->postJson(bankUrl('/statement'),bankInput())->assertConflict()->assertJsonPath('error.code','REFERENCE_ALREADY_RECORDED');
 $match=['operation_id'=>(string)Str::uuid(),'line_id'=>$line['id'],'payment_type'=>'supplier','payment_id'=>$this->alphaPayment->publicId()];
 $this->postJson(bankUrl('/match'),$match)->assertOk()->assertJsonPath('data.line.payment_id',$this->alphaPayment->publicId());
 $this->postJson(bankUrl('/match'),$match)->assertOk()->assertJsonPath('data.replayed',true);
 $this->postJson(bankUrl('/match'),[...$match,'operation_id'=>(string)Str::uuid()])->assertConflict();
 $this->context->run($this->alpha,function(){expect(BankStatementLine::count())->toBe(1);expect(SupplierPayment::count())->toBe(1);expect($this->alphaInvoice->fresh()->remaining_amount)->toBe(9000);});
});
it('rejects amount direction tenant authority and changed retry payloads',function(){
 $body=bankInput(['amount'=>2999]);$line=$this->postJson(bankUrl('/statement'),$body)->assertOk()->json('data.line');
 $this->postJson(bankUrl('/statement'),[...$body,'amount'=>3000])->assertConflict()->assertJsonPath('error.code','OPERATION_ID_REUSED');
 $this->postJson(bankUrl('/match'),['operation_id'=>(string)Str::uuid(),'line_id'=>$line['id'],'payment_type'=>'supplier','payment_id'=>$this->alphaPayment->publicId()])->assertUnprocessable()->assertJsonPath('error.code','BANK_MATCH_MISMATCH');
 $this->postJson(bankUrl('/match'),['operation_id'=>(string)Str::uuid(),'line_id'=>$line['id'],'payment_type'=>'supplier','payment_id'=>$this->betaPayment->publicId()])->assertNotFound();
 $this->withToken($this->alphaManagerToken)->getJson(bankUrl())->assertForbidden();
 $this->withToken($this->betaOwnerToken)->getJson(str_replace('alpha.','beta.',bankUrl()))->assertOk()->assertJsonCount(0,'data.lines');
});
it('plans only currently unpaid selected invoices and never pays them automatically',function(){
 $body=['operation_id'=>(string)Str::uuid(),'invoice_id'=>$this->alphaInvoice->publicId(),'due_on'=>'2026-10-11','amount'=>6000];
 $schedule=$this->postJson(bankUrl('/schedule'),$body)->assertOk()->assertJsonPath('data.schedule.overdue',true)->json('data.schedule');
 $this->postJson(bankUrl('/schedule'),$body)->assertOk()->assertJsonPath('data.replayed',true);
 $this->postJson(bankUrl('/schedule'),[...$body,'operation_id'=>(string)Str::uuid(),'amount'=>4000])->assertConflict()->assertJsonPath('error.code','PAYABLE_CHANGED');
 $this->postJson(bankUrl('/cancel'),['operation_id'=>(string)Str::uuid(),'schedule_id'=>$schedule['id']])->assertOk()->assertJsonPath('data.schedule.status','cancelled');
 $this->context->run($this->alpha,function(){expect(SupplierPaymentSchedule::count())->toBe(1);expect($this->alphaInvoice->fresh()->remaining_amount)->toBe(9000);});
});
it('reports historical same base unit prices without exposing another business',function(){
 $this->getJson('http://alpha.bizismart.test/api/v1/supplier-price-comparisons')->assertOk()->assertJsonCount(1,'data.products')->assertJsonPath('data.products.0.receipts.0.cost_price',3000)->assertJsonPath('data.products.0.unit','kg');
 $this->withToken($this->alphaManagerToken)->getJson('http://alpha.bizismart.test/api/v1/supplier-price-comparisons')->assertForbidden();
 $this->withToken($this->alphaOwnerToken)->postJson(bankUrl('/schedule'),['operation_id'=>(string)Str::uuid(),'invoice_id'=>$this->betaInvoice->publicId(),'due_on'=>'2026-10-15','amount'=>1])->assertNotFound();
 $this->postJson(bankUrl('/statement'),bankInput(['amount'=>0]))->assertUnprocessable();
 $this->postJson(bankUrl('/statement'),bankInput(['business_id'=>$this->beta->id]))->assertUnprocessable();
});

