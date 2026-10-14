<?php
use App\Models\{Business,Branch,Customer,Device,Plan,Product,ProductBatch,Sale,SaleDebt,StockMovement,SupervisorApproval,TillCashEntry,User};
use App\Services\{StaffRoleService,StockService,TillService,SaleService};
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);

beforeEach(function(){
    config(['tenancy.domain'=>'bizismart.test']);$this->context=app(TenantContext::class);
    $plan=Plan::create(['code'=>'discounts','name'=>'Discounts','price_monthly'=>0,'limits'=>['tills'=>10],'features'=>[]]);
    foreach(['alpha','beta'] as $label){
        $shop=Business::create(['name'=>$label,'subdomain'=>$label,'plan_id'=>$plan->id,'owner_phone'=>'0700000001']);$this->{$label}=$shop;
        $this->context->run($shop,function()use($label){
            app(StaffRoleService::class)->provision();$branch=Branch::create(['name'=>'Main']);$this->{$label.'Branch'}=$branch;
            $device=Device::create(['device_uid'=>'discounts','name'=>'Discount till','status'=>'approved']);$this->{$label.'Device'}=$device;
            foreach(['owner','cashier','manager','stockkeeper'] as $i=>$role){
                $actor=User::create(['name'=>$role,'phone'=>'071000000'.$i,'pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$branch->id]);app(StaffRoleService::class)->assign($actor,$role);
                if($role==='owner'){$actor->totp_secret='JBSWY3DPEHPK3PXP';$actor->totp_confirmed_at=now();$actor->save();}
                $token=$actor->createToken('discounts',['*'],now()->addDay());$token->accessToken->device_id=$device->id;$token->accessToken->save();$actor->withAccessToken($token->accessToken);
                $this->{$label.ucfirst($role)}=$actor;$this->{$label.ucfirst($role).'Token'}=$token->plainTextToken;
            }
            $this->{$label.'Till'}=app(TillService::class)->open($this->{$label.'Cashier'},['operation_id'=>(string)Str::uuid(),'branch_id'=>$branch->publicId(),'opening_float'=>20000])['till'];
            foreach(['Product'=>8500,'Second'=>4000] as $suffix=>$price){
                $product=Product::create(['name'=>$suffix,'quantity_type'=>'counted','unit'=>'bottle','selling_price'=>$price,'buying_price'=>100,'reorder_level_atoms'=>0,'is_active'=>true]);$this->{$label.$suffix}=$product;
                app(StockService::class)->receive($this->{$label.'Stockkeeper'},$product,['operation_id'=>(string)Str::uuid(),'batch_number'=>$suffix,'qty'=>'20','cost_price'=>100,'expiry_date'=>null]);
            }
            $this->{$label.'Customer'}=Customer::create(['name'=>'Asha','credit_limit'=>7000])->fresh();
        });
    }
    $this->withToken($this->alphaCashierToken);
    $this->discountInput=['branch_id'=>$this->alphaBranch->publicId(),'till_id'=>$this->alphaTill['id'],
        'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1','discount'=>500],['product_id'=>$this->alphaSecond->publicId(),'qty'=>'2','discount'=>0]],
        'discount'=>500,'discount_reason'=>'Customer goodwill'];
});
afterEach(function(){ $this->context->clear();$this->travelBack();TillCashEntry::flushEventListeners();\Illuminate\Database\Eloquent\Model::clearBootedModels(); });
function discountUrl(string $suffix=''):string { return 'http://alpha.bizismart.test/api/v1/sales'.$suffix; }
function discountReview($test,array $input=[]):array {return $test->postJson(discountUrl('/preview'),$input?:$test->discountInput)->assertOk()->json('data');}
function discountGrant($test,array $review,string $key,array $credentials=[]):string {
    return $test->postJson('http://alpha.bizismart.test/api/v1/auth/supervisor-check',[
        'phone'=>'0710000002','pin'=>'1234','permission'=>'give_discount','operation_id'=>$key,'payload_hash'=>$review['review_hash'],...$credentials,
    ])->assertOk()->json('data.approval_token');
}
function discountCommitBody($test,array $input=[],?string $key=null):array {
    $input=$input?:$test->discountInput;$key??=(string)Str::uuid();$review=discountReview($test,$input);
    return ['key'=>$key,'review'=>$review,'body'=>[...$input,'cash_tendered'=>$review['cash_allocated']??$review['total'],'review_hash'=>$review['review_hash'],'approval_token'=>discountGrant($test,$review,$key)]];
}
it('requires discount approval without creating a sale stock deduction or drawer entry',function(){
    $review=discountReview($this);
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(discountUrl(),[...$this->discountInput,'cash_tendered'=>15500,'review_hash'=>$review['review_hash']])->assertForbidden()->assertJsonPath('error.code','APPROVAL_REQUIRED');
    $this->context->run($this->alpha,function(){expect(Sale::count())->toBe(0);expect(StockMovement::where('type','sale')->count())->toBe(0);expect(TillCashEntry::count())->toBe(0);expect(ProductBatch::sum('qty_remaining_atoms'))->toBe('40');});
});
it('saves exact line and proportional cart discounts and refunds only original net value',function(){
    $commit=discountCommitBody($this);
    expect($commit['review']['subtotal'])->toBe(16000);expect($commit['review']['discount'])->toBe(500);expect($commit['review']['total'])->toBe(15500);
    expect(array_column($commit['review']['lines'],'line_total'))->toBe([7750,7750]);expect(array_column($commit['review']['lines'],'cart_discount'))->toBe([250,250]);
    $sale=$this->withHeader('Idempotency-Key',$commit['key'])->postJson(discountUrl(),$commit['body'])->assertCreated()->assertJsonPath('data.total',15500)->assertJsonPath('data.vat_amount',2364)->assertJsonPath('data.payments.0.amount',15500)->json('data');
    $return=['operation_id'=>(string)Str::uuid(),'sale_id'=>$sale['id'],'till_id'=>$this->alphaTill['id'],'reason'=>'Return discounted products','items'=>[['line_index'=>0,'qty'=>'1','disposition'=>'sellable'],['line_index'=>1,'qty'=>'1','disposition'=>'sellable']]];
    $review=$this->postJson('http://alpha.bizismart.test/api/v1/returns/preview',$return)->assertOk()->assertJsonPath('data.refund_total',11625)->json('data');
    $grant=$this->postJson('http://alpha.bizismart.test/api/v1/auth/supervisor-check',['phone'=>'0710000002','pin'=>'1234','permission'=>'refund','operation_id'=>$return['operation_id'],'payload_hash'=>$review['review_hash']])->assertOk()->json('data.approval_token');
    $this->withHeader('Idempotency-Key',$return['operation_id'])->postJson('http://alpha.bizismart.test/api/v1/returns',[...$return,'review_hash'=>$review['review_hash'],'approval_token'=>$grant])->assertCreated()->assertJsonPath('data.cash_refund',11625);
    $this->context->run($this->alpha,function(){expect(app(TillService::class)->current($this->alphaCashier)['till']['expected_cash'])->toBe(23875);expect(Sale::first()->discount_reason)->toBe('Customer goodwill');});
});
it('replays a completed discounted sale after consuming the grant without duplicate financial writes',function(){
    $commit=discountCommitBody($this);$this->withHeader('Idempotency-Key',$commit['key']);
    $sale=$this->postJson(discountUrl(),$commit['body'])->assertCreated()->json('data');
    $this->postJson(discountUrl(),$commit['body'])->assertOk()->assertJsonPath('data.id',$sale['id'])->assertJsonPath('data.replayed',true);
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(discountUrl(),$commit['body'])->assertForbidden()->assertJsonPath('error.code','INVALID_APPROVAL');
    $this->context->run($this->alpha,function(){expect(Sale::count())->toBe(1);expect(StockMovement::where('type','sale')->count())->toBe(2);expect(TillCashEntry::where('type','cash_sale')->count())->toBe(1);expect(SupervisorApproval::whereNotNull('consumed_at')->count())->toBe(1);});
});
it('rejects changed quote and moved manager grants without consuming approval or stock',function(){
    $commit=discountCommitBody($this);
    $this->context->run($this->alpha,fn()=> $this->alphaProduct->update(['selling_price'=>8600]));
    $this->withHeader('Idempotency-Key',$commit['key'])->postJson(discountUrl(),$commit['body'])->assertConflict()->assertJsonPath('error.code','SALE_CHANGED');
    $this->context->run($this->alpha,fn()=> $this->alphaProduct->update(['selling_price'=>8500]));
    $this->context->run($this->alpha,function(){ $branch=Branch::create(['name'=>'Other']);$this->alphaManager->branch_id=$branch->id;$this->alphaManager->save(); });
    $this->postJson(discountUrl(),$commit['body'])->assertForbidden()->assertJsonPath('error.code','INVALID_APPROVAL');
    $this->context->run($this->alpha,function(){expect(Sale::count())->toBe(0);expect(SupervisorApproval::first()->consumed_at)->toBeNull();expect(ProductBatch::sum('qty_remaining_atoms'))->toBe('40');});
});
it('rolls back discount approval stock and sale when the cash journal fails and permits same-operation retry',function(){
    $commit=discountCommitBody($this);
    $realTills=app(TillService::class);
    $this->mock(TillService::class,fn($mock)=>$mock->shouldReceive('recordCash')->once()->andThrow(new \RuntimeException('Injected cash journal failure')));
    app()->forgetInstance(SaleService::class);
    $this->withHeader('Idempotency-Key',$commit['key'])->postJson(discountUrl(),$commit['body'])->assertStatus(500);
    $this->context->run($this->alpha,function(){
        expect(Sale::count())->toBe(0);expect(SupervisorApproval::first()->consumed_at)->toBeNull();expect(ProductBatch::sum('qty_remaining_atoms'))->toBe('40');
        expect(TillCashEntry::count())->toBe(0);expect(StockMovement::where('type','sale')->count())->toBe(0);
    });
    app()->instance(TillService::class,$realTills);app()->forgetInstance(SaleService::class);
    app('router')->getRoutes()->getByName('sales.complete')->flushController();
    $this->withHeader('Idempotency-Key',$commit['key'])->postJson(discountUrl(),$commit['body'])->assertCreated()->assertJsonPath('data.total',15500);
});
it('requires fresh owner approval even when the operator is the owner',function(){
    $this->withToken($this->alphaOwnerToken);
    $till=$this->context->run($this->alpha,fn()=>app(TillService::class)->open($this->alphaOwner,['operation_id'=>(string)Str::uuid(),'branch_id'=>$this->alphaBranch->publicId(),'opening_float'=>0])['till']);
    $input=[...$this->discountInput,'till_id'=>$till['id']];$review=discountReview($this,$input);$key=(string)Str::uuid();
    $body=[...$input,'cash_tendered'=>15500,'review_hash'=>$review['review_hash']];
    $this->withHeader('Idempotency-Key',$key)->postJson(discountUrl(),$body)->assertForbidden()->assertJsonPath('error.code','APPROVAL_REQUIRED');
    $this->postJson('http://alpha.bizismart.test/api/v1/auth/supervisor-check',['phone'=>'0710000000','pin'=>'1234','permission'=>'give_discount','operation_id'=>$key,'payload_hash'=>$review['review_hash']])->assertForbidden()->assertJsonPath('error.code','TOTP_REQUIRED');
    $grant=discountGrant($this,$review,$key,['phone'=>'0710000000','code'=>app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP')]);
    $this->postJson(discountUrl(),[...$body,'approval_token'=>$grant])->assertCreated()->assertJsonPath('data.total',15500);
});
it('checks credit limits against approved discounted value and rejects excessive discounts',function(){
    $input=[...$this->discountInput,'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1','discount'=>1000]],'customer_id'=>$this->alphaCustomer->publicId(),'credit_amount'=>7000];
    $commit=discountCommitBody($this,$input);
    $this->withHeader('Idempotency-Key',$commit['key'])->postJson(discountUrl(),$commit['body'])->assertCreated()->assertJsonPath('data.total',7000)->assertJsonPath('data.cash_tendered',0);
    $this->context->run($this->alpha,function(){expect(Customer::find($this->alphaCustomer->id)->outstanding_balance)->toBe(7000);expect(SaleDebt::first()->original_amount)->toBe(7000);expect(TillCashEntry::count())->toBe(0);});
    $this->postJson(discountUrl('/preview'),$input)->assertStatus(400)->assertJsonPath('error.code','CREDIT_LIMIT_EXCEEDED');
    $this->postJson(discountUrl('/preview'),[...$this->discountInput,'discount'=>16001])->assertUnprocessable();
    $this->postJson(discountUrl('/preview'),[...$this->discountInput,'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1','discount'=>8501]]])->assertUnprocessable();
});
