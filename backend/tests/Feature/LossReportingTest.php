<?php
use App\Models\{Branch,Business,Department,Device,LossAlert,PersonalAccessToken,Plan,Product,ProductBatch,Sale,SaleItem,SaleReturn,SaleReturnItem,StockMovement,User};
use App\Services\{StaffRoleService,StockService,TillService,SaleService};
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Concurrency;
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);
beforeEach(function(){
    config(['tenancy.domain'=>'bizismart.test']);$this->context=app(TenantContext::class);$this->travelTo(now()->setDate(2026,10,14)->setTime(9,0));
    $plan=Plan::create(['code'=>'loss','name'=>'Loss','price_monthly'=>0,'limits'=>['tills'=>5],'features'=>[]]);
    foreach(['alpha','beta']as $label){$shop=Business::create(['name'=>$label,'subdomain'=>$label,'plan_id'=>$plan->id,'owner_phone'=>'0700000001']);$this->{$label}=$shop;$this->context->run($shop,function()use($label){
        app(StaffRoleService::class)->provision();$branch=Branch::create(['name'=>'Main']);$this->{$label.'Branch'}=$branch;$device=Device::create(['device_uid'=>'loss-device','name'=>'Loss device','status'=>'approved']);
        foreach(['owner','stockkeeper','cashier','manager']as $index=>$role){$user=User::create(['name'=>$role,'phone'=>'071000000'.$index,'pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$branch->id]);app(StaffRoleService::class)->assign($user,$role);if($role==='owner'){$user->totp_secret='JBSWY3DPEHPK3PXP';$user->totp_confirmed_at=now();$user->save();}$token=$user->createToken('loss',['*'],now()->addDay());$token->accessToken->device_id=$device->id;$token->accessToken->save();$user->withAccessToken($token->accessToken);$this->{$label.ucfirst($role)}=$user;$this->{$label.ucfirst($role).'Token'}=$token->plainTextToken;}
        $department=Department::create(['name'=>'Beverages','current_name'=>'Beverages']);$product=Product::create(['name'=>'Water','quantity_type'=>'counted','unit'=>'bottle','selling_price'=>1180,'buying_price'=>800,'reorder_level_atoms'=>0,'is_active'=>true]);$product->department_id=$department->id;$product->save();$this->{$label.'Department'}=$department;$this->{$label.'Product'}=$product;
        $this->{$label.'Batch'}=app(StockService::class)->receive($this->{$label.'Owner'},$product,['operation_id'=>(string)Str::uuid(),'batch_number'=>'LOSS-BATCH','qty'=>'20','cost_price'=>800,'expiry_date'=>null])['batch'];
    });}
    $this->withToken($this->alphaOwnerToken);
});
afterEach(function(){$this->context->clear();$this->travelBack();StockMovement::flushEventListeners();\Illuminate\Database\Eloquent\Model::clearBootedModels();});
function lossUrl(string $path,string $shop='alpha'):string{return 'http://'.$shop.'.bizismart.test/api/v1/'.$path;}
function lossInput($test,array $extra=[]):array{return [...['operation_id'=>(string)Str::uuid(),'batch_id'=>$test->alphaBatch->publicId(),'qty'=>'3','category'=>'damage','reason'=>'Broken bottles'],...$extra];}
function lossGrant($test,array $review):string{return $test->postJson(lossUrl('auth/supervisor-check'),['phone'=>'0710000000','pin'=>'1234','code'=>app(\PragmaRX\Google2FA\Google2FA::class)->oathTotp('JBSWY3DPEHPK3PXP',intdiv(now()->timestamp,30)),'permission'=>'adjust_stock','operation_id'=>$review['snapshot']['operation_id'],'payload_hash'=>$review['payload_hash']])->assertOk()->json('data.approval_token');}
function lossWorker(int $business,int $user,int $token,array $input):Closure{return static function()use($business,$user,$token,$input){if(!app()->environment('testing')||!str_ends_with(config('database.connections.mysql.database'),'_test'))throw new LogicException('Dedicated test database required.');app(TenantContext::class)->set(Business::findOrFail($business));$actor=User::findOrFail($user)->withAccessToken(PersonalAccessToken::findOrFail($token));try{$result=app(StockService::class)->commitLossCount($actor,$input,false);return ['id'=>$result['movement']->publicId(),'replayed'=>$result['replayed']];}catch(\App\Exceptions\ApiException $e){return ['error'=>$e->errorCode];}};}
it('records classified loss at exact batch cost once with immutable department snapshots',function(){
    $input=lossInput($this);$review=$this->postJson(lossUrl('stock/losses/preview'),$input)->assertOk()->assertJsonPath('data.loss_value',2400)->assertJsonPath('data.qty_after','17')->json('data');
    $body=[...$input,'expected_qty'=>$review['snapshot']['qty_before']];$id=$this->postJson(lossUrl('stock/losses'),$body)->assertCreated()->assertJsonPath('data.loss.loss_value',2400)->assertJsonPath('data.movement.type','stock_loss')->assertJsonPath('data.movement.approved_by',$this->alphaOwner->publicId())->json('data.movement.id');
    $this->postJson(lossUrl('stock/losses'),$body)->assertOk()->assertJsonPath('data.replayed',true)->assertJsonPath('data.movement.id',$id);
    $this->postJson(lossUrl('stock/losses'),[...$body,'category'=>'spoilage'])->assertConflict();
    $this->context->run($this->alpha,function(){expect(ProductBatch::firstOrFail()->qty_remaining_atoms)->toBe(17);expect(StockMovement::where('type','stock_loss')->count())->toBe(1);$this->alphaProduct->department_id=null;$this->alphaProduct->save();$this->alphaDepartment->delete();});
    $this->getJson(lossUrl('reports/losses?from=2026-10-14&to=2026-10-14&department_id='.$this->alphaDepartment->publicId()))->assertOk()->assertJsonPath('data.totals.damage',2400)->assertJsonPath('data.losses.0.loss.department_name','Beverages')->assertJsonPath('data.count',1);
    $this->getJson(lossUrl('reports/summary?from=2026-10-14&to=2026-10-14'))->assertOk()->assertJsonPath('data.summary.stock_loss_cost',2400)->assertJsonPath('data.summary.net_profit',-2400);
});
it('requires owner approval for stockkeeper losses and binds category and rejects other tenants',function(){
    $input=lossInput($this);$this->withToken($this->alphaStockkeeperToken);$review=$this->postJson(lossUrl('stock/losses/preview'),$input)->assertOk()->assertJsonPath('data.approval_required',true)->json('data');$body=[...$input,'expected_qty'=>'20'];$this->postJson(lossUrl('stock/losses'),$body)->assertForbidden();$grant=lossGrant($this,$review);
    $this->postJson(lossUrl('stock/losses'),[...$body,'category'=>'wastage','approval_token'=>$grant])->assertForbidden();
    $this->postJson(lossUrl('stock/losses'),[...$body,'approval_token'=>$grant])->assertCreated();
    $this->withToken($this->alphaCashierToken)->postJson(lossUrl('stock/losses/preview'),$input)->assertForbidden();
    $this->withToken($this->betaOwnerToken)->postJson(lossUrl('stock/losses/preview','beta'),$input)->assertNotFound();
    $this->getJson(lossUrl('reports/losses?from=2026-10-14&to=2026-10-14','beta'))->assertOk()->assertJsonCount(0,'data.losses');
});
it('counts department batches with stale balances and assignments rejected and zero variance journaled once',function(){
    $input=lossInput($this,['qty'=>'18','department_id'=>$this->alphaDepartment->publicId()]);unset($input['category']);$review=$this->postJson(lossUrl('stock/counts/preview'),$input)->assertOk()->assertJsonPath('data.snapshot.qty_change','-2')->json('data');$body=[...$input,'expected_qty'=>$review['snapshot']['qty_before']];$this->postJson(lossUrl('stock/counts'),$body)->assertCreated()->assertJsonPath('data.movement.type','stock_count');$this->postJson(lossUrl('stock/counts'),$body)->assertOk()->assertJsonPath('data.replayed',true);
    $this->postJson(lossUrl('stock/counts'),[...$body,'operation_id'=>(string)Str::uuid()])->assertConflict()->assertJsonPath('error.code','STOCK_CHANGED');
    $zero=[...$input,'operation_id'=>(string)Str::uuid(),'qty'=>'18','expected_qty'=>'18'];$this->postJson(lossUrl('stock/counts'),$zero)->assertCreated()->assertJsonPath('data.movement.qty_change','0');
    $this->context->run($this->alpha,function(){$this->alphaProduct->department_id=null;$this->alphaProduct->save();});$this->postJson(lossUrl('stock/counts/preview'),$input)->assertConflict();
    $this->getJson(lossUrl('reports/losses?from=2026-10-14&to=2026-10-14'))->assertOk()->assertJsonPath('data.totals.damage',0);
});
it('rolls stock loss back if ledger creation fails and validates exact positive quantities',function(){
    $input=lossInput($this);foreach(['0','-1','1.5','1e2',1]as $qty)$this->postJson(lossUrl('stock/losses/preview'),[...$input,'qty'=>$qty])->assertUnprocessable();$this->postJson(lossUrl('stock/losses/preview'),[...$input,'qty'=>'21'])->assertStatus(400);$this->postJson(lossUrl('stock/losses/preview'),[...$input,'cost_price'=>1])->assertUnprocessable();
    $highCost=$this->context->run($this->alpha,fn()=>app(StockService::class)->receive($this->alphaOwner,$this->alphaProduct,['operation_id'=>(string)Str::uuid(),'batch_number'=>'HIGH-COST','qty'=>'3','cost_price'=>\App\Support\Money::MAX_AMOUNT,'expiry_date'=>null])['batch']);$this->postJson(lossUrl('stock/losses/preview'),[...$input,'batch_id'=>$highCost->publicId()])->assertUnprocessable()->assertJsonPath('error.code','VALIDATION_ERROR');
    \Illuminate\Support\Facades\Event::listen('eloquent.created: App\\Models\\StockMovement',fn()=>throw new RuntimeException('Injected ledger failure'));$this->postJson(lossUrl('stock/losses'),[...$input,'expected_qty'=>'20'])->assertStatus(500);$this->context->run($this->alpha,fn()=>expect(ProductBatch::findOrFail($this->alphaBatch->id)->qty_remaining_atoms)->toBe(20));
});
it('evaluates disabled owner thresholds without alerts and deduplicates acknowledged evidence',function(){
    $this->getJson(lossUrl('loss-alerts'))->assertOk()->assertJsonPath('data.configuration.refund_count',null);
    $this->postJson(lossUrl('loss-alerts/refresh'))->assertOk()->assertJsonPath('data.created',0);
    $this->context->run($this->alpha,function(){for($n=0;$n<2;$n++)activity('sales')->performedOn($this->alphaProduct)->causedBy($this->alphaCashier)->event('sale_voided')->log('Explicit test completed void');});
    $this->putJson(lossUrl('loss-alerts/configuration'),['refund_count'=>null,'refund_amount'=>null,'void_count'=>2])->assertOk();$this->postJson(lossUrl('loss-alerts/refresh'))->assertOk()->assertJsonPath('data.created',1);$id=$this->getJson(lossUrl('loss-alerts'))->assertOk()->assertJsonPath('data.alerts.0.evidence.observed',2)->json('data.alerts.0.id');$this->postJson(lossUrl('loss-alerts/'.$id.'/acknowledge'))->assertOk();$this->postJson(lossUrl('loss-alerts/refresh'))->assertOk()->assertJsonPath('data.created',0);
    $this->withToken($this->betaOwnerToken)->postJson(lossUrl('loss-alerts/'.$id.'/acknowledge','beta'))->assertNotFound();$this->withToken($this->alphaManagerToken)->getJson(lossUrl('loss-alerts'))->assertForbidden();
});
it('reports department receipt economics and current assignment without duplicate batch totals',function(){
    $this->context->run($this->alpha,function(){
        $till=app(TillService::class)->open($this->alphaCashier,['operation_id'=>(string)Str::uuid(),'branch_id'=>$this->alphaBranch->publicId(),'opening_float'=>20000])['till'];$input=['branch_id'=>$this->alphaBranch->publicId(),'till_id'=>$till['id'],'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'2']]];$review=app(SaleService::class)->preview($this->alphaCashier,$input);app(SaleService::class)->complete($this->alphaCashier,[...$input,'review_hash'=>$review['review_hash'],'cash_tendered'=>3000],(string)Str::uuid());
    });
    $this->getJson(lossUrl('reports/departments?from=2026-10-14&to=2026-10-14'))->assertOk()->assertJsonPath('data.classification','current_product_department')->assertJsonPath('data.departments.0.net_revenue',2000)->assertJsonPath('data.departments.0.net_cogs',1600)->assertJsonPath('data.departments.0.gross_profit',400)->assertJsonPath('data.departments.0.recorded_stock_value',14400);
    $this->getJson(lossUrl('reports/tills?from=2026-10-14&to=2026-10-14'))->assertOk()->assertJsonPath('data.tills.0.expected_cash',22360)->assertJsonPath('data.tills.0.period_cash_entries.cash_sale',2360);
    $this->withToken($this->betaOwnerToken)->getJson(lossUrl('reports/departments?from=2026-10-14&to=2026-10-14','beta'))->assertOk()->assertJsonPath('data.departments.0.net_revenue',0);
});
it('uses exact gram loss quantities and current batch cost without decimal money',function(){
    $batch=$this->context->run($this->alpha,function(){$product=Product::create(['name'=>'Rice','quantity_type'=>'weight','unit'=>'kg','selling_price'=>4000,'buying_price'=>3000,'reorder_level_atoms'=>0,'is_active'=>true]);return app(StockService::class)->receive($this->alphaOwner,$product,['operation_id'=>(string)Str::uuid(),'batch_number'=>'RICE','qty'=>'0.750','cost_price'=>3000,'expiry_date'=>null])['batch'];});
    $input=lossInput($this,['batch_id'=>$batch->publicId(),'qty'=>'0.125','category'=>'spoilage']);$review=$this->postJson(lossUrl('stock/losses/preview'),$input)->assertOk()->assertJsonPath('data.qty_after','0.625')->assertJsonPath('data.loss_value',375)->json('data');$this->postJson(lossUrl('stock/losses'),[...$input,'expected_qty'=>$review['snapshot']['qty_before']])->assertCreated()->assertJsonPath('data.loss.loss_value',375);
});
it('reverses department revenue VAT and original costs on return event dates and alerts real refunds',function(){
    $this->context->run($this->alpha,function(){$this->till=app(TillService::class)->open($this->alphaOwner,['operation_id'=>(string)Str::uuid(),'branch_id'=>$this->alphaBranch->publicId(),'opening_float'=>20000])['till'];});
    $saleInput=['branch_id'=>$this->alphaBranch->publicId(),'till_id'=>$this->till['id'],'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'2']]];$saleReview=$this->postJson(lossUrl('sales/preview'),$saleInput)->assertOk()->json('data');$sale=$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(lossUrl('sales'),[...$saleInput,'review_hash'=>$saleReview['review_hash'],'cash_tendered'=>3000])->assertCreated()->json('data');
    $body=['operation_id'=>(string)Str::uuid(),'sale_id'=>$sale['id'],'till_id'=>$this->till['id'],'reason'=>'Customer return','items'=>[['line_index'=>0,'qty'=>'1','disposition'=>'sellable']]];$review=$this->postJson(lossUrl('returns/preview'),$body)->assertOk()->json('data');$grant=$this->postJson(lossUrl('auth/supervisor-check'),['phone'=>'0710000003','pin'=>'1234','permission'=>'refund','operation_id'=>$body['operation_id'],'payload_hash'=>$review['review_hash']])->assertOk()->json('data.approval_token');$this->withHeader('Idempotency-Key',$body['operation_id'])->postJson(lossUrl('returns'),[...$body,'review_hash'=>$review['review_hash'],'approval_token'=>$grant])->assertCreated();
    $this->getJson(lossUrl('reports/departments?from=2026-10-14&to=2026-10-14'))->assertOk()->assertJsonPath('data.departments.0.net_revenue',1000)->assertJsonPath('data.departments.0.net_cogs',800)->assertJsonPath('data.departments.0.gross_profit',200);
    $this->putJson(lossUrl('loss-alerts/configuration'),['refund_count'=>1,'refund_amount'=>1180,'void_count'=>null])->assertOk();$this->postJson(lossUrl('loss-alerts/refresh'))->assertOk()->assertJsonPath('data.created',2);
});
it('serializes competing duplicate loss submissions with exactly one deduction',function(){
    $input=[...lossInput($this),'expected_qty'=>'20'];$worker=lossWorker($this->alpha->id,$this->alphaOwner->id,$this->alphaOwner->currentAccessToken()->id,$input);\Illuminate\Support\Facades\DB::commit();\Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated=false;$results=Concurrency::driver('process')->run([$worker,$worker]);expect($results[0]['id'])->toBe($results[1]['id']);expect(array_column($results,'replayed'))->toContain(true,false);$this->context->run($this->alpha,function(){expect(ProductBatch::firstOrFail()->qty_remaining_atoms)->toBe(17);expect(StockMovement::where('type','stock_loss')->count())->toBe(1);});
});


