<?php
use App\Models\{Business,Branch,Customer,Device,Plan,Product,ProductBatch,Sale,SaleDebt,SaleExchange,SaleReturn,SaleReturnItem,StockMovement,SupervisorApproval,TillCashEntry,User,PersonalAccessToken};
use App\Services\{StaffRoleService,StockService,TillService,ReturnService};
use App\Tenancy\TenantContext;
use Illuminate\Support\{Str,Facades\DB,Facades\Concurrency};
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);
beforeEach(function(){
 config(['tenancy.domain'=>'bizismart.test']);$this->context=app(TenantContext::class);
 $plan=Plan::create(['code'=>'returns','name'=>'Returns','price_monthly'=>0,'limits'=>['tills'=>10],'features'=>[]]);
 foreach(['alpha','beta'] as $label){
  $shop=Business::create(['name'=>$label,'subdomain'=>$label,'plan_id'=>$plan->id,'owner_phone'=>'0700000001']);$this->{$label}=$shop;
  $this->context->run($shop,function()use($label){
   app(StaffRoleService::class)->provision();$branch=Branch::create(['name'=>'Main']);$this->{$label.'Branch'}=$branch;
   $device=Device::create(['device_uid'=>'returns','name'=>'Returns','status'=>'approved']);
   foreach(['cashier','manager','stockkeeper'] as $i=>$role){
    $actor=User::create(['name'=>$role,'phone'=>'071000000'.$i,'pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$branch->id]);app(StaffRoleService::class)->assign($actor,$role);
    $token=$actor->createToken('returns',['*'],now()->addDay());$token->accessToken->device_id=$device->id;$token->accessToken->save();$actor->withAccessToken($token->accessToken);
    $this->{$label.ucfirst($role)}=$actor;$this->{$label.ucfirst($role).'Token'}=$token->plainTextToken;
   }
   $this->{$label.'Till'}=app(TillService::class)->open($this->{$label.'Cashier'},['operation_id'=>(string)Str::uuid(),'branch_id'=>$branch->publicId(),'opening_float'=>20000])['till'];
   $p=Product::create(['name'=>'Rice','quantity_type'=>'counted','unit'=>'bag','selling_price'=>10000,'buying_price'=>100,'reorder_level_atoms'=>0,'is_active'=>true]);$this->{$label.'Product'}=$p;
   $this->{$label.'Batch'}=app(StockService::class)->receive($this->{$label.'Stockkeeper'},$p,['operation_id'=>(string)Str::uuid(),'batch_number'=>'A','qty'=>'10','cost_price'=>100,'expiry_date'=>null])['batch'];
   $this->{$label.'Customer'}=Customer::create(['name'=>'Asha','credit_limit'=>50000])->fresh();
  });
 }
 $this->withToken($this->alphaCashierToken);
});
afterEach(function(){$this->context->clear();$this->travelBack();});
function returnUrl(string $path=''):string{return 'http://alpha.bizismart.test/api/v1/returns'.$path;}
function returnSale($t,int $credit=0,string $qty='2'):array{
 $body=['branch_id'=>$t->alphaBranch->publicId(),'till_id'=>$t->alphaTill['id'],'customer_id'=>$t->alphaCustomer->publicId(),'credit_amount'=>$credit,'items'=>[['product_id'=>$t->alphaProduct->publicId(),'qty'=>$qty]]];
 $r=$t->postJson('http://alpha.bizismart.test/api/v1/sales/preview',$body)->assertOk()->json('data');
 return $t->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('http://alpha.bizismart.test/api/v1/sales',[...$body,'cash_tendered'=>$r['cash_allocated'],'review_hash'=>$r['review_hash']])->assertCreated()->json('data');
}
function returnBody($t,array $sale,string $qty='1',string $disposition='sellable'):array{return ['operation_id'=>(string)Str::uuid(),'sale_id'=>$sale['id'],'till_id'=>$t->alphaTill['id'],'reason'=>'Customer return','items'=>[['line_index'=>0,'qty'=>$qty,'disposition'=>$disposition]]];}
function returnCommit($t,array $body):array{
 $r=$t->postJson(returnUrl('/preview'),$body)->assertOk()->json('data');
 $g=$t->postJson('http://alpha.bizismart.test/api/v1/auth/supervisor-check',['phone'=>'0710000001','pin'=>'1234','permission'=>'refund','operation_id'=>$body['operation_id'],'payload_hash'=>$r['review_hash']])->assertOk()->json('data');
 return [...$body,'review_hash'=>$r['review_hash'],'approval_token'=>$g['approval_token']];
}
function refundProcess(int $business,int $actor,int $token,array $input):Closure{return static function()use($business,$actor,$token,$input){
 if(!app()->environment('testing')||!str_ends_with(config('database.connections.mysql.database'),'_test'))throw new LogicException('Test database required.');
 app(TenantContext::class)->set(Business::findOrFail($business));$user=User::findOrFail($actor)->withAccessToken(PersonalAccessToken::findOrFail($token));
 try{return app(ReturnService::class)->complete($user,$input)['id'];}catch(\App\Exceptions\ApiException $e){return $e->errorCode;}
};}
it('audits a full remaining receipt void once and feeds the actual void threshold without changing refund rules',function(){
 $sale=returnSale($this);$ordinary=returnCommit($this,returnBody($this,$sale));$this->withHeader('Idempotency-Key',$ordinary['operation_id'])->postJson(returnUrl(),$ordinary)->assertCreated();
 $body=returnCommit($this,[...returnBody($this,$sale),'operation_kind'=>'void']);
 $this->withHeader('Idempotency-Key',$body['operation_id'])->postJson(returnUrl(),[...$body,'operation_kind'=>'return'])->assertConflict()->assertJsonPath('error.code','RETURN_CHANGED');
 $this->postJson(returnUrl(),$body)->assertCreated()->assertJsonPath('data.refund_total',10000)->assertJsonPath('data.cash_refund',10000);
 $this->postJson(returnUrl(),$body)->assertOk()->assertJsonPath('data.replayed',true);
 $this->context->run($this->alpha,function()use($sale){$audits=\App\Models\Activity::where('event','sale_voided')->get();expect($audits)->toHaveCount(1);expect($audits[0]->properties['sale_id'])->toBe($sale['id']);expect($audits[0]->causer_id)->toBe($this->alphaCashier->id);expect(SaleReturn::count())->toBe(2);expect(ProductBatch::find($this->alphaBatch->id)->qty_remaining_atoms)->toBe(10);expect(TillCashEntry::where('type','cash_refund')->sum('amount'))->toBe('20000');
  $shop=Business::findOrFail($this->alpha->id);$settings=$shop->settings??[];$settings['loss_alerts']=['refund_count'=>null,'refund_amount'=>null,'void_count'=>1];$shop->settings=$settings;$shop->save();$date=now('Africa/Dar_es_Salaam')->toDateString();expect(app(\App\Services\LossAlertService::class)->evaluate($date))->toBe(1);expect(app(\App\Services\LossAlertService::class)->evaluate($date))->toBe(0);expect(\App\Models\LossAlert::first()->evidence['observed'])->toBe(1);
 });
 $this->context->run($this->beta,function(){expect(\App\Models\Activity::where('event','sale_voided')->count())->toBe(0);expect(\App\Models\LossAlert::count())->toBe(0);});
});
it('rejects a partial void and an exchange marked as void before stock payment or audit writes',function(){
 $sale=returnSale($this);$partial=[...returnBody($this,$sale),'operation_kind'=>'void'];$this->postJson(returnUrl('/preview'),$partial)->assertUnprocessable()->assertJsonPath('error.code','VOID_QUANTITY_REQUIRED');
 $exchange=[...returnBody($this,$sale,'2'),'operation_kind'=>'void','replacement'=>['items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1']],'cash_tendered'=>0]];$this->postJson(returnUrl('/preview'),$exchange)->assertUnprocessable()->assertJsonPath('error.code','VOID_EXCHANGE_UNAVAILABLE');
 $this->context->run($this->alpha,function(){expect(SaleReturn::count())->toBe(0);expect(ProductBatch::find($this->alphaBatch->id)->qty_remaining_atoms)->toBe(8);expect(\App\Models\Activity::where('event','sale_voided')->count())->toBe(0);expect(TillCashEntry::where('type','cash_refund')->count())->toBe(0);});
});
it('does not classify an ordinary full receipt refund as an explicit void',function(){
 $sale=returnSale($this);$body=returnCommit($this,returnBody($this,$sale,'2'));$this->withHeader('Idempotency-Key',$body['operation_id'])->postJson(returnUrl(),$body)->assertCreated()->assertJsonPath('data.refund_total',20000);
 $this->context->run($this->alpha,function(){expect(\App\Models\Activity::where('event','sale_returned')->count())->toBe(1);expect(\App\Models\Activity::where('event','sale_voided')->count())->toBe(0);});
});
it('requires every remaining original line for a completed receipt void',function(){
 $this->context->run($this->alpha,function(){$p=Product::create(['name'=>'Beans','quantity_type'=>'counted','unit'=>'bag','selling_price'=>5000,'buying_price'=>100,'reorder_level_atoms'=>0,'is_active'=>true]);$this->otherVoidProduct=$p;app(StockService::class)->receive($this->alphaStockkeeper,$p,['operation_id'=>(string)Str::uuid(),'batch_number'=>'VOID-SECOND','qty'=>'3','cost_price'=>100,'expiry_date'=>null]);});
 $input=['branch_id'=>$this->alphaBranch->publicId(),'till_id'=>$this->alphaTill['id'],'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1'],['product_id'=>$this->otherVoidProduct->publicId(),'qty'=>'1']]];$review=$this->postJson('http://alpha.bizismart.test/api/v1/sales/preview',$input)->assertOk()->json('data');$sale=$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('http://alpha.bizismart.test/api/v1/sales',[...$input,'cash_tendered'=>$review['total'],'review_hash'=>$review['review_hash']])->assertCreated()->json('data');
 $body=[...returnBody($this,$sale),'operation_kind'=>'void'];$this->postJson(returnUrl('/preview'),$body)->assertUnprocessable()->assertJsonPath('error.code','VOID_QUANTITY_REQUIRED');
 $body['items'][]=['line_index'=>1,'qty'=>'1','disposition'=>'sellable'];$this->postJson(returnUrl('/preview'),$body)->assertOk()->assertJsonPath('data.operation_kind','void')->assertJsonPath('data.refund_total',15000);
});
it('restores original stock once and replays consumed approval after closure',function(){
 $sale=returnSale($this);$body=returnCommit($this,returnBody($this,$sale));
 $r=$this->withHeader('Idempotency-Key',$body['operation_id'])->postJson(returnUrl(),$body)->assertCreated()->assertJsonPath('data.cash_refund',10000)->assertJsonPath('data.replayed',false)->assertJsonMissingPath('data.business_id')->json('data');
 $this->getJson(returnUrl('/receipt?receipt_number='.$sale['receipt_number']))->assertOk()->assertHeader('Cache-Control','no-store, private')->assertJsonPath('data.lines.0.remaining_qty','1')->assertJsonPath('data.return_status','partial');
 $this->context->run($this->alpha,function(){expect(ProductBatch::find($this->alphaBatch->id)->qty_remaining_atoms)->toBe(9);expect(SaleReturnItem::sum('qty_atoms'))->toBe('1');$t=\App\Models\TillSession::first();$t->status='closed';$t->closed_at=now();$t->save();});
 $this->postJson(returnUrl(),$body)->assertOk()->assertJsonPath('data.id',$r['id'])->assertJsonPath('data.replayed',true);
 $this->postJson(returnUrl(),[...$body,'reason'=>'Changed'])->assertConflict()->assertJsonPath('error.code','OPERATION_ID_REUSED');
});
it('reduces original debt first and refunds prior cash collections without exceeding quantity',function(){
 $sale=returnSale($this,10000);$pay=['till_id'=>$this->alphaTill['id'],'method'=>'cash','amount'=>4000,'allocations'=>[['sale_id'=>$sale['id'],'amount'=>4000]]];$url='http://alpha.bizismart.test/api/v1/customers/'.$this->alphaCustomer->publicId().'/payments';
 $r=$this->postJson($url.'/preview',$pay)->assertOk()->json('data');$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson($url,[...$pay,'review_hash'=>$r['review_hash']])->assertCreated();
 $body=returnCommit($this,returnBody($this,$sale));$this->withHeader('Idempotency-Key',$body['operation_id'])->postJson(returnUrl(),$body)->assertCreated()->assertJsonPath('data.debt_reduction',6000)->assertJsonPath('data.cash_refund',4000);
 $this->context->run($this->alpha,function(){expect(Customer::first()->outstanding_balance)->toBe(0);expect(SaleDebt::first()->remaining_amount)->toBe(0);expect(TillCashEntry::where('type','cash_refund')->sum('amount'))->toBe('4000');});
 $again=returnCommit($this,returnBody($this,$sale));$this->withHeader('Idempotency-Key',$again['operation_id'])->postJson(returnUrl(),$again)->assertCreated()->assertJsonPath('data.cash_refund',10000);
 $this->postJson(returnUrl('/preview'),returnBody($this,$sale))->assertUnprocessable()->assertJsonPath('error.code','RETURN_QUANTITY_INVALID');
});
it('quarantines damaged expired and recalled goods separately from good stock',function(){
 $sale=returnSale($this);$body=returnCommit($this,returnBody($this,$sale,'1','damaged'));$this->withHeader('Idempotency-Key',$body['operation_id'])->postJson(returnUrl(),$body)->assertCreated();
 $this->context->run($this->alpha,function(){$s=ProductBatch::find($this->alphaBatch->id);expect($s->qty_remaining_atoms)->toBe(8);expect($s->is_quarantined)->toBeFalse();$q=ProductBatch::where('source_batch_id',$s->id)->first();expect($q->qty_remaining_atoms)->toBe(1);expect($q->is_quarantined)->toBeTrue();$s->expiry_date=now('Africa/Dar_es_Salaam')->subDay()->toDateString();$s->is_recalled=true;$s->save();});
 $body=returnCommit($this,returnBody($this,$sale));$this->withHeader('Idempotency-Key',$body['operation_id'])->postJson(returnUrl(),$body)->assertCreated();
 $this->context->run($this->alpha,function(){expect(ProductBatch::where('source_batch_id',$this->alphaBatch->id)->sum('qty_remaining_atoms'))->toBe('2');expect(ProductBatch::find($this->alphaBatch->id)->qty_remaining_atoms)->toBe(8);});
});
it('enforces receipt tenant branch and manager grant scope at commit',function(){
 $sale=returnSale($this);$body=returnCommit($this,returnBody($this,$sale));
 $this->withToken($this->betaCashierToken)->getJson(str_replace('alpha.','beta.',returnUrl('/receipt?receipt_number='.$sale['receipt_number'])))->assertNotFound();
 $this->withToken($this->alphaStockkeeperToken)->getJson(returnUrl('/receipt?receipt_number='.$sale['receipt_number']))->assertForbidden();
 $this->context->run($this->alpha,function(){$br=Branch::create(['name'=>'Elsewhere']);$u=User::find($this->alphaManager->id);$u->branch_id=$br->id;$u->save();});
 $this->withToken($this->alphaCashierToken)->withHeader('Idempotency-Key',$body['operation_id'])->postJson(returnUrl(),$body)->assertForbidden()->assertJsonPath('error.code','INVALID_APPROVAL');
 $this->context->run($this->alpha,function(){expect(SaleReturn::count())->toBe(0);$u=User::find($this->alphaCashier->id);$u->branch_id=Branch::where('name','Elsewhere')->first()->id;$u->save();});
 $this->getJson(returnUrl('/receipt?receipt_number='.$sale['receipt_number']))->assertForbidden();
});
it('exchanges atomically collecting the difference and reusing freed customer credit',function(){
 $sale=returnSale($this,20000);$this->context->run($this->alpha,function(){$c=Customer::first();$c->credit_limit=20000;$c->save();});
 $body=returnBody($this,$sale);$body['replacement']=['items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'2']],'credit_amount'=>10000,'cash_tendered'=>10000];$body=returnCommit($this,$body);
 $this->withHeader('Idempotency-Key',$body['operation_id'])->postJson(returnUrl(),$body)->assertCreated()->assertJsonPath('data.debt_reduction',10000)->assertJsonPath('data.cash_collected',10000)->assertJsonPath('data.cash_paid',0)->assertJsonPath('data.replacement.total',20000);
 $this->context->run($this->alpha,function(){expect(Customer::first()->outstanding_balance)->toBe(20000);expect(SaleExchange::count())->toBe(1);expect(Sale::count())->toBe(2);expect(ProductBatch::find($this->alphaBatch->id)->qty_remaining_atoms)->toBe(7);});
});
it('rolls back stock grant and cash when the replacement cannot be sold',function(){
 $sale=returnSale($this);$body=returnBody($this,$sale);$body['replacement']=['items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'20']],'cash_tendered'=>190000];$body=returnCommit($this,$body);
 $this->withHeader('Idempotency-Key',$body['operation_id'])->postJson(returnUrl(),$body)->assertStatus(400)->assertJsonPath('error.code','INSUFFICIENT_STOCK');
 $this->context->run($this->alpha,function(){expect(SaleReturn::count())->toBe(0);expect(SaleReturnItem::count())->toBe(0);expect(SaleExchange::count())->toBe(0);expect(Sale::count())->toBe(1);expect(ProductBatch::find($this->alphaBatch->id)->qty_remaining_atoms)->toBe(8);expect(TillCashEntry::where('type','cash_refund')->count())->toBe(0);expect(SupervisorApproval::first()->consumed_at)->toBeNull();});
});
it('rejects stale reviews client totals and missing approval without writes',function(){
 $sale=returnSale($this);$input=returnBody($this,$sale);$this->postJson(returnUrl('/preview'),[...$input,'refund_total'=>9000])->assertUnprocessable();$body=returnCommit($this,$input);
 $this->context->run($this->alpha,function(){$b=ProductBatch::find($this->alphaBatch->id);$b->is_recalled=true;$b->save();});
 $this->withHeader('Idempotency-Key',$body['operation_id'])->postJson(returnUrl(),$body)->assertConflict()->assertJsonPath('error.code','RETURN_CHANGED');unset($body['approval_token']);$this->postJson(returnUrl(),$body)->assertUnprocessable();
 $this->context->run($this->alpha,function(){expect(SaleReturn::count())->toBe(0);expect(StockMovement::where('type','return')->count())->toBe(0);});
});
it('returns exact grams including a zero-money partial line without rounding drift',function(){
 $this->context->run($this->alpha,function(){
  $p=Product::create(['name'=>'Weighed rice','quantity_type'=>'weight','unit'=>'kg','selling_price'=>667,'buying_price'=>100,'reorder_level_atoms'=>0,'is_active'=>true]);$this->alphaProduct=$p;
  $this->alphaBatch=app(StockService::class)->receive($this->alphaStockkeeper,$p,['operation_id'=>(string)Str::uuid(),'batch_number'=>'GRAMS','qty'=>'1.000','cost_price'=>100,'expiry_date'=>null])['batch'];
 });
 $sale=returnSale($this,0,'0.003');expect($sale['total'])->toBe(2);
 foreach([1,0,1] as $amount){$body=returnCommit($this,returnBody($this,$sale,'0.001'));$this->withHeader('Idempotency-Key',$body['operation_id'])->postJson(returnUrl(),$body)->assertCreated()->assertJsonPath('data.refund_total',$amount);}
 $this->context->run($this->alpha,function(){expect(ProductBatch::find($this->alphaBatch->id)->qty_remaining_atoms)->toBe(1000);expect(SaleReturn::sum('refund_total'))->toBe('2');expect(StockMovement::where('type','return')->count())->toBe(3);expect(TillCashEntry::where('type','cash_refund')->count())->toBe(2);});
});
it('lets owners return another branch receipt through their own open till',function(){
 $sale=returnSale($this);$this->context->run($this->alpha,function(){
  $br=Branch::create(['name'=>'Owner branch']);$device=Device::first();
  $owner=User::create(['name'=>'Owner','phone'=>'0710000010','pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$br->id]);app(StaffRoleService::class)->assign($owner,'owner');$owner->totp_secret='JBSWY3DPEHPK3PXP';$owner->totp_confirmed_at=now();$owner->save();
  $token=$owner->createToken('returns',['*'],now()->addDay());$token->accessToken->device_id=$device->id;$token->accessToken->save();$owner->withAccessToken($token->accessToken);$this->ownerToken=$token->plainTextToken;
  $this->ownerTill=app(TillService::class)->open($owner,['operation_id'=>(string)Str::uuid(),'branch_id'=>$br->publicId(),'opening_float'=>20000])['till'];
 });
 $this->withToken($this->ownerToken)->getJson(returnUrl('/receipt?receipt_number='.$sale['receipt_number']))->assertOk();
 $body=returnBody($this,$sale);$body['till_id']=$this->ownerTill['id'];$body=returnCommit($this,$body);
 $this->withHeader('Idempotency-Key',$body['operation_id'])->postJson(returnUrl(),$body)->assertCreated()->assertJsonPath('data.cash_paid',10000);
 $this->context->run($this->alpha,function(){$e=TillCashEntry::where('type','cash_refund')->first();expect(\App\Models\TillSession::find($e->till_session_id)->publicId())->toBe($this->ownerTill['id']);});
});
it('rolls debt balances back together with an unsuccessful replacement',function(){
 $sale=returnSale($this,20000);$body=returnBody($this,$sale);$body['replacement']=['items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'20']],'cash_tendered'=>200000];$body=returnCommit($this,$body);
 $this->withHeader('Idempotency-Key',$body['operation_id'])->postJson(returnUrl(),$body)->assertStatus(400)->assertJsonPath('error.code','INSUFFICIENT_STOCK');
 $this->context->run($this->alpha,function(){expect(Customer::first()->outstanding_balance)->toBe(20000);expect(SaleDebt::first()->remaining_amount)->toBe(20000);expect(SaleReturn::count())->toBe(0);expect(ProductBatch::find($this->alphaBatch->id)->qty_remaining_atoms)->toBe(8);expect(SupervisorApproval::first()->consumed_at)->toBeNull();});
});
it('serializes duplicate and conflicting returns in independent MySQL processes',function(){
 $sale=returnSale($this,0,'1');$body=returnCommit($this,returnBody($this,$sale));$second=returnCommit($this,returnBody($this,$sale));$actor=$this->alphaCashier;
 DB::commit();\Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated=false;
 $job=refundProcess($this->alpha->id,$actor->id,$actor->currentAccessToken()->id,$body);$r=Concurrency::driver('process')->run([$job,$job]);expect($r[0])->toStartWith('ret_');expect($r[0])->toBe($r[1]);
 $r=Concurrency::driver('process')->run([refundProcess($this->alpha->id,$actor->id,$actor->currentAccessToken()->id,$second)]);expect($r)->toBe(['RETURN_QUANTITY_INVALID']);$this->context->set($this->alpha);expect(SaleReturn::count())->toBe(1);expect(SaleReturnItem::sum('qty_atoms'))->toBe('1');expect(ProductBatch::find($this->alphaBatch->id)->qty_remaining_atoms)->toBe(10);
 $raceSale=returnSale($this,0,'1');$a=returnCommit($this,returnBody($this,$raceSale));$b=returnCommit($this,returnBody($this,$raceSale));
 $race=Concurrency::driver('process')->run([refundProcess($this->alpha->id,$actor->id,$actor->currentAccessToken()->id,$a),refundProcess($this->alpha->id,$actor->id,$actor->currentAccessToken()->id,$b)]);
 expect(count(array_filter($race,fn($v)=>str_starts_with($v,'ret_'))))->toBe(1);expect(count(array_filter($race,fn($v)=>$v==='RETURN_QUANTITY_INVALID')))->toBe(1);$this->context->set($this->alpha);expect(SaleReturn::count())->toBe(2);expect(ProductBatch::find($this->alphaBatch->id)->qty_remaining_atoms)->toBe(10);
});
