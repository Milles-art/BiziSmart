<?php
use App\Models\{Business,Branch,Device,Plan,User,Product,PersonalAccessToken};
use App\Services\StaffRoleService;
use App\Tenancy\TenantContext;
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);
beforeEach(function () {
    config(['tenancy.domain'=>'bizismart.test']); $this->context=app(TenantContext::class);
    $plan=Plan::create(['code'=>'enterprise','name'=>'Enterprise','price_monthly'=>0,'limits'=>[],'features'=>[]]);
    foreach (['alpha','beta'] as $label) {
        $shop=Business::create(['name'=>$label,'subdomain'=>$label,'plan_id'=>$plan->id,'owner_phone'=>'0700000001']); $this->{$label}=$shop;
        $this->context->run($shop,function () use ($label) {
            app(StaffRoleService::class)->provision(); $branch=Branch::create(['name'=>'Main']);
            $device=Device::create(['device_uid'=>'enterprise-test','name'=>'Enterprise test','status'=>'approved']);
            foreach (['owner','manager','cashier'] as $i=>$role) {
                $u=User::create(['name'=>$role,'phone'=>'071000000'.$i,'pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$branch->id]); app(StaffRoleService::class)->assign($u,$role);
                if ($role==='owner') { $u->totp_secret='JBSWY3DPEHPK3PXP'; $u->totp_confirmed_at=now(); $u->save(); }
                $token=$u->createToken('enterprise-test',['*'],now()->addDay()); $token->accessToken->device_id=$device->id; $token->accessToken->save(); $u->withAccessToken($token->accessToken);
                $this->{$label.ucfirst($role)}=$u; $this->{$label.ucfirst($role).'Token'}=$token->plainTextToken;
            }
            $this->{$label.'Product'}=Product::create(['name'=>'Enterprise tea','quantity_type'=>'counted','unit'=>'box','selling_price'=>2000,'buying_price'=>1200,'reorder_level_atoms'=>0,'is_active'=>true]);
        });
    }
    $this->withToken($this->alphaOwnerToken);
});
afterEach(function () { $this->context->clear(); $this->travelBack(); \Illuminate\Database\Eloquent\Model::clearBootedModels(); });
function enterpriseUrl(string $path,string $shop='alpha'): string { return 'http://'.$shop.'.bizismart.test/api/v1'.$path; }
function enterpriseToken($test,string $key='alphaOwnerToken'): string {
    return $test->withToken($test->{$key})->postJson(enterpriseUrl('/enterprise/tokens'),['name'=>'Nightly export','expires_in_days'=>30])->assertCreated()->json('data.token');
}
it('issues lists and revokes scoped read tokens with one-time plaintext',function () {
    $issued=$this->postJson(enterpriseUrl('/enterprise/tokens'),['name'=>'Nightly export','expires_in_days'=>30])->assertCreated()
        ->assertJsonPath('data.name','Nightly export')->assertJsonPath('data.abilities',['enterprise:read'])->json('data');
    expect($issued['id'])->toStartWith('tok_')->and($issued['token'])->toBeString()->and($issued['expires_at'])->not->toBeNull();
    $list=$this->getJson(enterpriseUrl('/enterprise/tokens'))->assertOk()->assertJsonCount(1,'data.tokens')->json('data.tokens');
    expect($list[0])->toMatchArray(['id'=>$issued['id'],'name'=>'Nightly export'])->and($list[0])->not->toHaveKeys(['token']);
    $this->context->run($this->alpha,fn()=>expect(PersonalAccessToken::where('public_id','!=',null)->count())->toBeGreaterThanOrEqual(1));
    $this->deleteJson(enterpriseUrl('/enterprise/tokens/'.$issued['id']))->assertOk()->assertJsonPath('data.revoked',true);
    $this->getJson(enterpriseUrl('/enterprise/tokens'))->assertOk()->assertJsonCount(0,'data.tokens');
    $this->withToken($issued['token'])->getJson(enterpriseUrl('/enterprise/products'))->assertUnauthorized();
});
it('restricts credential management to owners and hides staff sessions',function () {
    $this->withToken($this->alphaManagerToken)->postJson(enterpriseUrl('/enterprise/tokens'),['name'=>'Manager key'])->assertForbidden();
    $this->withToken($this->alphaCashierToken)->getJson(enterpriseUrl('/enterprise/tokens'))->assertForbidden();
    $staffPublic=$this->context->run($this->alpha,fn()=>PersonalAccessToken::whereJsonDoesntContain('abilities','enterprise:read')->firstOrFail()->publicId());
    $this->withToken($this->alphaOwnerToken)->deleteJson(enterpriseUrl('/enterprise/tokens/'.$staffPublic))->assertNotFound();
    $this->deleteJson(enterpriseUrl('/enterprise/tokens/tok_00000000000000000000000000'))->assertNotFound();
});
it('serves owner-level reads to integration tokens and rejects staff sessions',function () {
    $this->freezeTime();
    $data=$this->context->run($this->alpha,function(){
        $actor=$this->alphaOwner;$product=$this->alphaProduct;$department=\App\Models\Department::create(['name'=>'Enterprise groceries']);
        $product->department_id=$department->id;$product->reorder_level_atoms=5;$product->tracks_expiry=true;$product->save();
        $pack=app(\App\Services\PackagingService::class)->save($actor,$product,['name'=>'Twin box','base_qty'=>'2','selling_price'=>3800]);
        $receipt=app(\App\Services\StockService::class)->receive($actor,$product,['operation_id'=>(string)\Illuminate\Support\Str::uuid(),'batch_number'=>'ENTERPRISE-BATCH','qty'=>'3','cost_price'=>1200,'expiry_date'=>now('Africa/Dar_es_Salaam')->addDays(5)->toDateString()]);
        Business::findOrFail($this->alpha->id)->plan->update(['limits'=>['tills'=>2]]);
        $till=app(\App\Services\TillService::class)->open($actor,['operation_id'=>(string)\Illuminate\Support\Str::uuid(),'branch_id'=>$actor->branch->publicId(),'opening_float'=>10000])['till'];
        $input=['branch_id'=>$actor->branch->publicId(),'till_id'=>$till['id'],'items'=>[['product_id'=>$product->publicId(),'qty'=>'1']]];
        $review=app(\App\Services\SaleService::class)->preview($actor,$input);
        $sale=app(\App\Services\SaleService::class)->complete($actor,[...$input,'cash_tendered'=>$review['total'],'review_hash'=>$review['review_hash']],(string)\Illuminate\Support\Str::uuid())['sale'];
        return ['pack'=>$pack->publicId(),'department'=>$department->publicId(),'batch'=>$receipt['batch']->publicId(),'sale'=>$sale->publicId(),'till'=>$till['id']];
    });
    $token=enterpriseToken($this);
    $product=$this->alphaProduct->publicId();$date=now('Africa/Dar_es_Salaam')->toDateString();$period='?from='.$date.'&to='.$date;
    $cases=[
        ['/enterprise/products','data.products.0.id',$product],
        ['/enterprise/products/'.$product,'data.id',$product],
        ['/enterprise/products/'.$product.'/packagings','data.packagings.0.id',$data['pack']],
        ['/enterprise/products/'.$product.'/batches','data.batches.0.id',$data['batch']],
        ['/enterprise/products/'.$product.'/movements','data.movements.0.type','sale'],
        ['/enterprise/stock/reorder-alerts','data.products.0.id',$product],
        ['/enterprise/stock/expiry-alerts','data.batches.0.id',$data['batch']],
        ['/enterprise/reports/summary'.$period,'data.summary.sale_count',1],
        ['/enterprise/reports/sales'.$period,'data.sales.0.id',$data['sale']],
        ['/enterprise/reports/stock'.$period,'data.stock.0.qty','2'],
        ['/enterprise/departments','data.departments.0.id',$data['department']],
        ['/enterprise/till-sessions','data.tills.0.id',$data['till']],
    ];
    foreach($cases as [$path,$field,$expected])$this->withToken($token)->getJson(enterpriseUrl($path))->assertOk()->assertHeader('Cache-Control','no-store, private')->assertJsonStructure(['success','data','request_id'])->assertJsonPath('success',true)->assertJsonPath($field,$expected);
    $this->withToken($token)->getJson(enterpriseUrl('/enterprise/products'))->assertJsonPath('data.products.0.buying_price',1200)->assertJsonPath('data.products.0.stock','2');
    $this->withToken($token)->getJson(enterpriseUrl('/enterprise/till-sessions'))->assertJsonPath('data.tills.0.expected_cash',12000);
    $this->withToken($this->alphaCashierToken)->getJson(enterpriseUrl('/enterprise/products'))->assertForbidden();
    $this->withToken($this->alphaManagerToken)->getJson(enterpriseUrl('/enterprise/products'))->assertForbidden();
    $this->withToken('bogus-token')->getJson(enterpriseUrl('/enterprise/products'))->assertUnauthorized();
});
it('isolates integration tokens by tenant and honours the api_access flag',function () {
    $token=enterpriseToken($this);
    $this->withToken($token)->getJson(enterpriseUrl('/enterprise/products','beta'))->assertUnauthorized()->assertHeader('Cache-Control','no-store, private');
    $this->withToken($token)->getJson(enterpriseUrl('/enterprise/products','missing'))->assertNotFound()->assertHeader('Cache-Control','no-store, private');
    $this->withToken($token)->postJson(enterpriseUrl('/enterprise/products'))->assertStatus(405)->assertHeader('Cache-Control','no-store, private');
    $this->context->run($this->alpha,function () { $shop=Business::firstOrFail(); $settings=$shop->settings??[]; $settings['feature_overrides']=['api_access'=>false]; $shop->settings=$settings; $shop->save(); });
    $this->withToken($token)->getJson(enterpriseUrl('/enterprise/products'))->assertForbidden()->assertJsonPath('error.code','FEATURE_DISABLED')->assertHeader('Cache-Control','no-store, private');
});
it('rechecks interactive authority inside credential management after a session is revoked or expires',function () {
    $this->context->run($this->alpha,function(){
        $actor=$this->alphaOwner;$target=$actor->createToken('Existing integration',['enterprise:read'],now()->addDay())->accessToken;
        PersonalAccessToken::whereKey($actor->currentAccessToken()->id)->delete();
        $service=app(\App\Services\EnterpriseService::class);
        expect(fn()=>$service->issue($actor,['name'=>'After revocation']))->toThrow(\Illuminate\Auth\AuthenticationException::class);
        expect(fn()=>$service->index($actor))->toThrow(\Illuminate\Auth\AuthenticationException::class);
        expect(fn()=>$service->revoke($actor,$target))->toThrow(\Illuminate\Auth\AuthenticationException::class);
        expect(PersonalAccessToken::whereJsonContains('abilities','enterprise:read')->count())->toBe(1);
        $session=$actor->createToken('Expired interactive',['*'],now()->subMinute())->accessToken;$session->device_id=$actor->currentAccessToken()->device_id;$session->save();$actor->withAccessToken($session);
        expect(fn()=>$service->issue($actor,['name'=>'After expiry']))->toThrow(\Illuminate\Auth\AuthenticationException::class);
        expect(PersonalAccessToken::whereKey($target->id)->exists())->toBeTrue();
    });
});
it('rechecks device approval before minting an integration key from a captured owner session',function () {
    $this->context->run($this->alpha,function(){
        $actor=$this->alphaOwner;Device::whereKey($actor->currentAccessToken()->device_id)->update(['status'=>'blocked']);
        expect(fn()=>app(\App\Services\EnterpriseService::class)->issue($actor,['name'=>'After device block']))->toThrow(\Illuminate\Auth\AuthenticationException::class);
        expect(PersonalAccessToken::whereJsonContains('abilities','enterprise:read')->count())->toBe(0);
    });
});
it('preserves underlying module features on headless aliases',function(string $feature){
    $token=enterpriseToken($this);
    $this->context->run($this->alpha,function()use($feature){$shop=Business::findOrFail($this->alpha->id);$settings=$shop->settings??[];$settings['feature_overrides']=['api_access'=>true,$feature=>false];$shop->settings=$settings;$shop->save();});
    $product=$this->alphaProduct->publicId();$period='?from=2026-10-14&to=2026-10-14';
    $paths=match($feature){'reports'=>['/enterprise/reports/summary'.$period,'/enterprise/reports/sales'.$period,'/enterprise/reports/stock'.$period],'inventory'=>['/enterprise/products/'.$product.'/batches','/enterprise/products/'.$product.'/movements','/enterprise/stock/reorder-alerts','/enterprise/stock/expiry-alerts'],'departments'=>['/enterprise/departments']};
    foreach($paths as $path)$this->withToken($token)->getJson(enterpriseUrl($path))->assertForbidden()->assertJsonPath('error.code','FEATURE_DISABLED')->assertHeader('Cache-Control','no-store, private');
    $this->withToken($token)->getJson(enterpriseUrl('/enterprise/products'))->assertOk();
})->with(['reports','inventory','departments']);
it('rejects credentials that are not dedicated headless keys',function(string $kind){
    $token=$this->context->run($this->alpha,function()use($kind){
        $abilities=match($kind){'mixed'=>['enterprise:read','customers:write'],'wildcard'=>['enterprise:read','*'],default=>['enterprise:read']};
        $issued=$this->alphaOwner->createToken('Not dedicated',$abilities,$kind==='unbounded'?null:now()->addDay());
        if($kind==='device'){$issued->accessToken->device_id=$this->alphaOwner->currentAccessToken()->device_id;$issued->accessToken->save();}
        return $issued->plainTextToken;
    });
    $response=$this->withToken($token)->getJson(enterpriseUrl('/enterprise/products'));
    $kind==='unbounded'?$response->assertUnauthorized():$response->assertForbidden();
})->with(['mixed','wildcard','device','unbounded']);
it('requires current owner security state and revokes access on expiry demotion and account deactivation',function(){
    $this->freezeTime();$token=enterpriseToken($this);
    foreach(['must_change_pin','totp_confirmed_at'] as $field){
        $this->context->run($this->alpha,function()use($field){$user=User::findOrFail($this->alphaOwner->id);$user->{$field}=$field==='must_change_pin'?true:null;$user->save();});
        $this->withToken($token)->getJson(enterpriseUrl('/enterprise/products'))->assertUnauthorized();
        $this->context->run($this->alpha,function()use($field){$user=User::findOrFail($this->alphaOwner->id);$user->{$field}=$field==='must_change_pin'?false:now();$user->save();});
    }
    $this->context->run($this->alpha,fn()=>app(StaffRoleService::class)->assign(User::findOrFail($this->alphaOwner->id),'manager'));
    $this->withToken($token)->getJson(enterpriseUrl('/enterprise/products'))->assertForbidden();
    $this->context->run($this->alpha,fn()=>app(StaffRoleService::class)->assign(User::findOrFail($this->alphaOwner->id),'owner'));
    $this->withToken($token)->getJson(enterpriseUrl('/enterprise/products'))->assertOk();
    $this->travel(31)->days();$this->withToken($token)->getJson(enterpriseUrl('/enterprise/products'))->assertUnauthorized();$this->travelBack();
    $this->context->run($this->alpha,fn()=>User::findOrFail($this->alphaOwner->id)->update(['is_active'=>false]));
    $this->withToken($token)->getJson(enterpriseUrl('/enterprise/products'))->assertUnauthorized();
});
it('rejects multiple owner roles and cannot use a headless key for ordinary interactive writes or credential management',function(){
    $token=enterpriseToken($this);$this->withToken($token)->postJson(enterpriseUrl('/products'),['name'=>'Unauthorized product'])->assertUnauthorized();
    $this->withToken($token)->postJson(enterpriseUrl('/enterprise/tokens'),['name'=>'Child credential'])->assertUnauthorized();
    $this->withToken($token)->getJson(enterpriseUrl('/enterprise/tokens'))->assertUnauthorized();
    $this->context->run($this->alpha,function(){User::findOrFail($this->alphaOwner->id)->assignRole(\App\Models\Role::findByName('manager','web'));});
    $this->withToken($token)->getJson(enterpriseUrl('/enterprise/products'))->assertUnauthorized();
    $this->context->run($this->alpha,fn()=>expect(Product::where('name','Unauthorized product')->count())->toBe(0));
});
