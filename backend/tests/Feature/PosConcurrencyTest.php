<?php
use App\Models\{Business,Branch,Device,Plan,Product,ProductBatch,Sale,SupervisorApproval,TillCashEntry,User,PersonalAccessToken,HeldCart,HeldCartOperation};
use App\Services\{StaffRoleService,StockService,TillService,HoldService,SaleService};
use App\Tenancy\TenantContext;
use Illuminate\Support\{Str,Facades\Concurrency};
use Tests\Concerns\RefreshPaymentDatabase;
// Independent subprocesses need committed fixtures; this guard cleans only the dedicated *_test schema.
uses(RefreshPaymentDatabase::class);
beforeEach(function(){
    config(['tenancy.domain'=>'bizismart.test']);$this->context=app(TenantContext::class);
    $plan=Plan::create(['code'=>'pos-races','name'=>'POS races','price_monthly'=>0,'limits'=>['tills'=>5],'features'=>[]]);
    $this->shop=Business::create(['name'=>'Race shop','subdomain'=>'alpha','plan_id'=>$plan->id,'owner_phone'=>'0700000001']);
    $this->context->run($this->shop,function(){
        app(StaffRoleService::class)->provision();$this->branch=Branch::create(['name'=>'Main']);
        $device=Device::create(['device_uid'=>'pos-races','name'=>'Race device','status'=>'approved']);
        foreach(['cashier','manager','stockkeeper'] as $i=>$role){
            $actor=User::create(['name'=>$role,'phone'=>'071000000'.$i,'pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$this->branch->id]);app(StaffRoleService::class)->assign($actor,$role);
            $token=$actor->createToken('pos-races',['*'],now()->addDay());$token->accessToken->device_id=$device->id;$token->accessToken->save();$actor->withAccessToken($token->accessToken);
            $this->{$role}=$actor;$this->{$role.'Token'}=$token->plainTextToken;
        }
        $this->till=app(TillService::class)->open($this->cashier,['operation_id'=>(string)Str::uuid(),'branch_id'=>$this->branch->publicId(),'opening_float'=>0])['till'];
        $this->product=Product::create(['name'=>'Last water','quantity_type'=>'counted','unit'=>'bottle','selling_price'=>8500,'buying_price'=>1,'reorder_level_atoms'=>0,'is_active'=>true]);
        app(StockService::class)->receive($this->stockkeeper,$this->product,['operation_id'=>(string)Str::uuid(),'batch_number'=>'LAST','qty'=>'1','cost_price'=>1,'expiry_date'=>null]);
    });
    $this->withToken($this->cashierToken);
});
afterEach(function(){$this->context->clear();});
function posRaceJob(int $shop,int $actor,int $token,string $action,array $body,string $key):Closure {
    return static function()use($shop,$actor,$token,$action,$body,$key){
        if(!app()->environment('testing')||!str_ends_with(config('database.connections.mysql.database'),'_test'))throw new LogicException('Dedicated test database required.');
        app(TenantContext::class)->set(Business::findOrFail($shop));$user=User::findOrFail($actor)->withAccessToken(PersonalAccessToken::findOrFail($token));
        try{
            if($action==='hold')return app(HoldService::class)->claim($user,$body['id'],'resume',$key);
            $result=app(SaleService::class)->complete($user,$body,$key);return ['id'=>$result['sale']->publicId(),'replayed'=>$result['replayed']];
        }catch(\App\Exceptions\ApiException $e){return ['error'=>$e->errorCode];}
    };
}
it('allows only one independent cashier to claim a branch-shared held cart',function(){
    $saved=$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('http://alpha.bizismart.test/api/v1/held-carts',['branch_id'=>$this->branch->publicId(),'items'=>[['product_id'=>$this->product->publicId(),'qty'=>'1']]])->assertCreated()->json('data');
    $jobs=[];foreach([$this->cashier,$this->manager] as $actor)$jobs[]=posRaceJob($this->shop->id,$actor->id,$actor->currentAccessToken()->id,'hold',['id'=>$saved['id']],(string)Str::uuid());
    $results=Concurrency::driver('process')->run($jobs);
    expect(array_column($results,'error'))->toBe(['HOLD_UNAVAILABLE']);expect(array_column($results,'status'))->toBe(['resumed']);
    $this->context->run($this->shop,function(){expect(HeldCart::first()->status)->toBe('resumed');expect(HeldCartOperation::count())->toBe(2);expect(Sale::count())->toBe(0);expect(TillCashEntry::count())->toBe(0);expect(ProductBatch::first()->qty_remaining_atoms)->toBe(1);});
});
it('allocates stock cash and one discount grant once across duplicate independent sale submissions',function(){
    $input=['branch_id'=>$this->branch->publicId(),'till_id'=>$this->till['id'],'items'=>[['product_id'=>$this->product->publicId(),'qty'=>'1','discount'=>500]],'discount'=>500,'discount_reason'=>'Checkout promotion'];
    $review=$this->postJson('http://alpha.bizismart.test/api/v1/sales/preview',$input)->assertOk()->json('data');$key=(string)Str::uuid();
    $grant=$this->postJson('http://alpha.bizismart.test/api/v1/auth/supervisor-check',['phone'=>$this->manager->phone,'pin'=>'1234','permission'=>'give_discount','operation_id'=>$key,'payload_hash'=>$review['review_hash']])->assertOk()->json('data.approval_token');
    $body=[...$input,'cash_tendered'=>7500,'review_hash'=>$review['review_hash'],'approval_token'=>$grant];
    $job=posRaceJob($this->shop->id,$this->cashier->id,$this->cashier->currentAccessToken()->id,'sale',$body,$key);
    $results=Concurrency::driver('process')->run([$job,$job]);
    expect($results[0]['id'])->toBe($results[1]['id']);expect(array_column($results,'replayed'))->toContain(true,false);
    $this->context->run($this->shop,function(){expect(Sale::count())->toBe(1);expect(Sale::first()->total)->toBe(7500);expect(TillCashEntry::count())->toBe(1);expect(TillCashEntry::first()->amount)->toBe(7500);expect(ProductBatch::first()->qty_remaining_atoms)->toBe(0);expect(SupervisorApproval::whereNotNull('consumed_at')->count())->toBe(1);});
});
