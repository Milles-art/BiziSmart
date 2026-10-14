<?php

use App\Models\Activity;
use App\Models\Business;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use App\Services\ProductService;
use App\Services\StaffRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $this->plan = Plan::create(['code' => 'catalogue', 'name' => 'Catalogue', 'price_monthly' => 0, 'limits' => ['products' => 10], 'features' => []]);
    foreach (['alpha', 'beta'] as $label) {
        $business = Business::create(['name' => $label, 'subdomain' => $label, 'plan_id' => $this->plan->id, 'owner_phone' => '0700000001']);
        $this->{$label} = $business;
        $this->context->run($business, function () use ($label) {
            app(StaffRoleService::class)->provision();
            $device = Device::create(['device_uid' => 'receiving-device', 'name' => 'Test device', 'status' => 'approved']);
            foreach (['owner', 'manager', 'cashier', 'stockkeeper'] as $index => $role) {
                $user = User::create(['name' => 'Test '.$role, 'phone' => '071000000'.$index, 'pin_hash' => '1234', 'must_change_pin' => false]);
                app(StaffRoleService::class)->assign($user, $role);
                if ($role === 'owner') {
                    $user->totp_secret = 'JBSWY3DPEHPK3PXP'; $user->totp_confirmed_at = now(); $user->save();
                }
                $token = $user->createToken('receiving-test', ['*'], now()->addDays(1));
                $token->accessToken->device_id = $device->id; $token->accessToken->save();
                $this->{$label.ucfirst($role).'Token'} = $token->plainTextToken;
                $this->{$label.ucfirst($role)} = $user;
            }
        });
    }
    $this->withToken($this->alphaOwnerToken);
    $this->input = ['name' => 'Water 500ml', 'barcode' => '06123456789012', 'category' => 'Beverages', 'unit' => 'bottle',
        'quantity_type' => 'counted', 'buying_price' => 800, 'selling_price' => 1200, 'reorder_level' => '50', 'tracks_expiry' => true];
});

afterEach(function () { $this->context->clear(); $this->travelBack(); \App\Models\StockMovement::flushEventListeners(); \App\Models\StockMovement::clearBootedModels(); });


function correctionUrl(string $suffix = ''): string { return 'http://alpha.bizismart.test/api/v1/stock/adjust'.$suffix; }
function correctionBatch($test, bool $weighted = false, string $qty = '100'): array {
    $product = $test->postJson('http://alpha.bizismart.test/api/v1/products', [...$test->input, 'quantity_type' => $weighted ? 'weight' : 'counted', 'unit' => $weighted ? 'kg' : 'bottle'])->assertCreated()->json('data.id');
    return $test->postJson('http://alpha.bizismart.test/api/v1/products/'.$product.'/batches', ['operation_id' => (string) \Illuminate\Support\Str::uuid(), 'batch_number' => 'DEL-1', 'qty' => $qty, 'cost_price' => 800, 'expiry_date' => now('Africa/Dar_es_Salaam')->addDays(10)->toDateString()])->assertCreated()->json('data.batch');
}
function correctionInput(array $batch, array $extra = []): array { return [...['batch_id' => $batch['id'], 'qty_change' => '-3', 'reason' => 'Damaged goods', 'operation_id' => (string) \Illuminate\Support\Str::uuid(), 'expected_qty' => $batch['qty_remaining']], ...$extra]; }
function correctionPreview($test, array $input): array { unset($input['expected_qty'], $input['approval_token']); return $test->postJson(correctionUrl('/preview'), $input)->assertOk()->json('data'); }
function correctionGrant($test, array $review): string { return $test->postJson('http://alpha.bizismart.test/api/v1/auth/supervisor-check', ['phone' => '0710000000', 'pin' => '1234', 'code' => app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP'), 'permission' => 'adjust_stock', 'operation_id' => $review['snapshot']['operation_id'], 'payload_hash' => $review['payload_hash']])->assertOk()->json('data.approval_token'); }
function correctionWorker(int $businessId, int $actorId, int $tokenId, array $input): Closure {
    return static function () use ($businessId, $actorId, $tokenId, $input) {
        if (! app()->environment('testing') || ! str_ends_with(config('database.connections.mysql.database'), '_test')) throw new LogicException('Dedicated test database required.');
        app(TenantContext::class)->set(Business::findOrFail($businessId));
        $user = User::findOrFail($actorId)->withAccessToken(\App\Models\PersonalAccessToken::findOrFail($tokenId));
        try { $r = app(\App\Services\StockService::class)->adjust($user, $input); return ['id' => $r['movement']->publicId(), 'replayed' => $r['replayed']]; } catch (\App\Exceptions\ApiException $e) { return ['error' => $e->errorCode]; }
    };
}
it('commits owner corrections with immutable replay encrypted reasons and public historical balances', function () {
    $batch=correctionBatch($this); $input=correctionInput($batch); $review=correctionPreview($this,$input); expect($review['qty_after'])->toBe('97'); expect($review['approval_required'])->toBeFalse();
    $this->context->run($this->alpha, fn()=>expect(\App\Models\StockMovement::count())->toBe(1));
    $saved=$this->postJson(correctionUrl(),$input)->assertCreated()->assertHeader('Cache-Control','no-store, private')->assertJsonPath('data.movement.qty_after','97')->assertJsonPath('data.movement.receipt',null)->assertJsonPath('data.movement.reason','Damaged goods')->json('data.movement');
    $this->postJson('http://alpha.bizismart.test/api/v1/products/'.$batch['product_id'].'/batches',['operation_id'=>$input['operation_id'],'batch_number'=>'COLLISION','qty'=>'1','cost_price'=>1,'expiry_date'=>now('Africa/Dar_es_Salaam')->addDay()->toDateString()])->assertConflict()->assertJsonPath('error.code','OPERATION_ID_REUSED');
    $this->postJson(correctionUrl('/preview'),array_diff_key(correctionInput($batch,['qty_change'=>'-0.5']),['expected_qty'=>true]))->assertUnprocessable();
    $this->postJson(correctionUrl(),correctionInput($batch,['qty_change'=>'1','expected_qty'=>'97']))->assertCreated();
    $this->postJson(correctionUrl(),$input)->assertOk()->assertJsonPath('data.replayed',true)->assertJsonPath('data.movement.id',$saved['id'])->assertJsonPath('data.movement.qty_after','97');
    foreach ([['reason'=>'Changed'],['operation_id'=>$batch['receipt']['operation_id']]] as $extra) $this->postJson(correctionUrl(),[...$input,...$extra])->assertConflict()->assertJsonPath('error.code','OPERATION_ID_REUSED');
    $this->context->run($this->alpha,function(){ $m=\App\Models\StockMovement::where('type','adjustment')->firstOrFail(); expect($m->getRawOriginal('reason'))->not->toBe('Damaged goods'); expect(json_encode(Activity::where('event','stock_adjusted')->firstOrFail()->properties))->not->toContain('Damaged goods','1234'); expect(\App\Models\ProductBatch::firstOrFail()->qty_remaining_atoms)->toBe(98); expect(\App\Models\StockMovement::count())->toBe(3); });
    $this->getJson('http://alpha.bizismart.test/api/v1/products/'.$batch['product_id'].'/movements')->assertOk()->assertJsonPath('data.movements.1.qty_after','97')->assertJsonMissingPath('data.movements.0.request_fingerprint');
});
it('validates exact gram quantities negative results and forbidden authority inputs',function(){
    $batch=correctionBatch($this,true,'0.750'); $input=correctionInput($batch,['qty_change'=>'-0.001']); expect(correctionPreview($this,$input)['qty_after'])->toBe('0.749'); $this->postJson(correctionUrl(),$input)->assertCreated()->assertJsonPath('data.movement.qty_after','0.749');
    foreach (['0','-0','0.0001',' 0.1','+1','01','1e2',1,'9223372036854775.808'] as $qty) $this->postJson(correctionUrl('/preview'),array_diff_key(correctionInput($batch,['qty_change'=>$qty]),['expected_qty'=>true]))->assertUnprocessable();
    $this->postJson(correctionUrl(),correctionInput($batch,['qty_change'=>'-1','expected_qty'=>'0.749']))->assertStatus(400)->assertJsonPath('error.code','INSUFFICIENT_STOCK');
    $this->postJson(correctionUrl(),[...$input,'reason'=>'   '])->assertUnprocessable(); $this->postJson(correctionUrl(),[...$input,'expected_qty'=>' 0.749'])->assertUnprocessable();
    foreach (['business_id','payload_hash','qty_after','approved_by','cost_price','packaging_id'] as $field) $this->postJson(correctionUrl(),[...$input,$field=>'untrusted'])->assertUnprocessable();
});
it('requires owner-only approval for every stockkeeper correction with one atomic consumption',function(){
    $batch=correctionBatch($this);$input=correctionInput($batch,['qty_change'=>'-1']);$this->withToken($this->alphaStockkeeperToken);$review=correctionPreview($this,$input);expect($review['approval_required'])->toBeTrue();$this->postJson(correctionUrl(),$input)->assertForbidden()->assertJsonPath('error.code','SUPERVISOR_REQUIRED');
    foreach (['0710000001','0710000003'] as $phone) $this->postJson('http://alpha.bizismart.test/api/v1/auth/supervisor-check',['phone'=>$phone,'pin'=>'1234','permission'=>'adjust_stock','operation_id'=>$input['operation_id'],'payload_hash'=>$review['payload_hash']])->assertForbidden()->assertJsonPath('error.code','APPROVER_NOT_AUTHORIZED');
    $grant=correctionGrant($this,$review);$this->postJson(correctionUrl(),[...$input,'approval_token'=>$grant])->assertCreated()->assertJsonPath('data.movement.approved_by',$this->alphaOwner->publicId());$this->postJson(correctionUrl(),[...$input,'approval_token'=>$grant])->assertOk()->assertJsonPath('data.replayed',true);
    $this->postJson(correctionUrl(),correctionInput($batch,['qty_change'=>'-1','expected_qty'=>'99','approval_token'=>$grant]))->assertForbidden()->assertJsonPath('error.code','INVALID_APPROVAL');$this->context->run($this->alpha,fn()=>expect(\App\Models\SupervisorApproval::firstOrFail()->consumed_at)->not->toBeNull());
});
it('rejects changed snapshots stale balance expired grants and another requester session',function(){
    $batch=correctionBatch($this);$input=correctionInput($batch);$this->withToken($this->alphaStockkeeperToken);$review=correctionPreview($this,$input);$grant=correctionGrant($this,$review);
    $this->postJson(correctionUrl(),[...$input,'reason'=>'Changed','approval_token'=>$grant])->assertForbidden()->assertJsonPath('error.code','INVALID_APPROVAL');
    $other=$this->context->run($this->alpha,function(){ $u=$this->alphaStockkeeper; $t=$u->createToken('other',['*'],now()->addDay());$t->accessToken->device_id=Device::firstOrFail()->id;$t->accessToken->save();return $t->plainTextToken; });$this->withToken($other)->postJson(correctionUrl(),[...$input,'approval_token'=>$grant])->assertForbidden();
    $this->withToken($this->alphaOwnerToken)->postJson(correctionUrl(),correctionInput($batch,['qty_change'=>'1']))->assertCreated();$this->withToken($this->alphaStockkeeperToken)->postJson(correctionUrl(),[...$input,'approval_token'=>$grant])->assertConflict()->assertJsonPath('error.code','STOCK_CHANGED');
    $this->context->run($this->alpha,fn()=>expect(\App\Models\SupervisorApproval::firstOrFail()->consumed_at)->toBeNull());$this->travel(6)->minutes();$this->postJson(correctionUrl(),[...$input,'expected_qty'=>'101','approval_token'=>$grant])->assertForbidden();$this->travelBack();
});
it('denies tenant leaks manager cashier and blocked devices without writes',function(){
    $batch=correctionBatch($this);$input=correctionInput($batch);foreach ([$this->alphaManagerToken,$this->alphaCashierToken] as $token){$this->withToken($token)->postJson(correctionUrl(),$input)->assertForbidden();$this->postJson(correctionUrl('/preview'),array_diff_key($input,['expected_qty'=>true]))->assertForbidden();}
    $this->withToken($this->betaOwnerToken)->postJson(str_replace('alpha.','beta.',correctionUrl()),$input)->assertNotFound();$this->withToken($this->alphaOwnerToken)->postJson(correctionUrl(),[...$input,'batch_id'=>'1'])->assertUnprocessable();
    $this->context->run($this->alpha,fn()=>Device::firstOrFail()->update(['status'=>'blocked']));$this->postJson(correctionUrl(),$input)->assertUnauthorized();$this->context->run($this->alpha,fn()=>expect(\App\Models\StockMovement::count())->toBe(1));
});
it('rolls back approval balance and ledger when a ledger write fails',function(){
    $batch=correctionBatch($this);$input=correctionInput($batch);$this->withToken($this->alphaStockkeeperToken);$grant=correctionGrant($this,correctionPreview($this,$input));\Illuminate\Support\Facades\Event::listen('eloquent.created: App\\Models\\StockMovement', fn () => throw new RuntimeException('Injected ledger failure'));$this->postJson(correctionUrl(),[...$input,'approval_token'=>$grant])->assertStatus(500);
    $this->context->run($this->alpha,function(){expect(\App\Models\ProductBatch::firstOrFail()->qty_remaining_atoms)->toBe(100);expect(\App\Models\StockMovement::count())->toBe(1);expect(\App\Models\SupervisorApproval::firstOrFail()->consumed_at)->toBeNull();expect(Activity::where('event','stock_adjusted')->count())->toBe(0);});
});
it('checks aggregate overflow while permitting inactive held stock corrections without changing flags',function(){
    $batch=correctionBatch($this);$input=correctionInput($batch);$this->postJson('http://alpha.bizismart.test/api/v1/products/'.$batch['product_id'].'/batches',['operation_id'=>(string)\Illuminate\Support\Str::uuid(),'batch_number'=>'MAX','qty'=>(string)(PHP_INT_MAX-100),'cost_price'=>1,'expiry_date'=>now('Africa/Dar_es_Salaam')->addDays(10)->toDateString()])->assertCreated();$this->postJson(correctionUrl(),[...$input,'qty_change'=>'1'])->assertUnprocessable();
    $this->context->run($this->alpha,function(){Product::firstOrFail()->update(['is_active'=>false]);\App\Models\ProductBatch::firstOrFail()->update(['is_quarantined'=>true,'expiry_date'=>now()->subDay()->toDateString()]);});$this->postJson(correctionUrl(),$input)->assertCreated();$this->context->run($this->alpha,fn()=>expect(\App\Models\ProductBatch::firstOrFail()->is_quarantined)->toBeTrue());$this->deleteJson('http://alpha.bizismart.test/api/v1/products/'.$batch['product_id'])->assertOk();$this->postJson(correctionUrl(),$input)->assertNotFound();
});
it('serializes duplicate corrections and competing deductions on independent MySQL connections',function(bool $duplicate){
    $batch=correctionBatch($this);$first=correctionInput($batch,['qty_change'=>'-60']);$second=$duplicate?$first:correctionInput($batch,['qty_change'=>'-60']);$businessId=$this->alpha->id;$actorId=$this->alphaOwner->id;$tokenId=(int)explode('|',$this->alphaOwnerToken)[0];\Illuminate\Support\Facades\DB::commit();\Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated=false;
    $results=\Illuminate\Support\Facades\Concurrency::driver('process')->run([correctionWorker($businessId,$actorId,$tokenId,$first),correctionWorker($businessId,$actorId,$tokenId,$second)]);if($duplicate){expect($results[0]['id'])->toBe($results[1]['id']);expect(array_column($results,'replayed'))->toContain(true,false);}else expect(array_column($results,'error'))->toBe(['STOCK_CHANGED']);
    $this->context->run($this->alpha,function(){expect(\App\Models\ProductBatch::firstOrFail()->qty_remaining_atoms)->toBe(40);expect(\App\Models\StockMovement::where('type','adjustment')->count())->toBe(1);});
})->with([true,false]);
