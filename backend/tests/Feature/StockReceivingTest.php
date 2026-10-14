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

afterEach(fn () => $this->context->clear());

function receiptUrl(string $product, string $shop = 'alpha'): string { return 'http://'.$shop.'.bizismart.test/api/v1/products/'.$product.'/batches'; }
function receiptInput(array $overrides = []): array { return [...['operation_id' => (string) \Illuminate\Support\Str::uuid(), 'batch_number' => 'DEL-1', 'qty' => '100', 'cost_price' => 800, 'expiry_date' => now('Africa/Dar_es_Salaam')->addDays(10)->toDateString()], ...$overrides]; }
function receiptProduct($test, array $overrides = []): string { return $test->postJson('http://alpha.bizismart.test/api/v1/products', [...$test->input, ...$overrides])->assertCreated()->json('data.id'); }
function concurrentReceiptJob(int $businessId, int $actorId, int $productId, array $input): Closure
{
    // Global closure scope avoids serializing Pest's generated test class into the artisan worker.
    return static function () use ($businessId, $actorId, $productId, $input) {
        if (! app()->environment('testing') || ! str_ends_with(config('database.connections.mysql.database'), '_test')) { throw new LogicException('Dedicated test database required.'); }
        $context = app(TenantContext::class); $context->set(Business::findOrFail($businessId));
        $result = app(\App\Services\StockService::class)->receive(User::findOrFail($actorId), Product::findOrFail($productId), $input);
        return ['id' => $result['batch']->publicId(), 'replayed' => $result['replayed']];
    };
}

it('receives counted stock atomically with immutable ledger, exact quantities and duplicate-safe replays', function () {
    $product = receiptProduct($this); $input = receiptInput();
    $result = $this->postJson(receiptUrl($product), $input)->assertCreated()->assertJsonPath('data.batch.qty_remaining', '100')
        ->assertJsonPath('data.batch.sellable', true)->assertJsonPath('data.movement.qty_change', '100')->assertJsonPath('data.movement.qty_after', '100')
        ->assertJsonPath('data.replayed', false)->assertJsonMissingPath('data.batch.business_id')->assertHeader('Cache-Control', 'no-store, private');
    expect($result->json('data.batch.id'))->toStartWith('bat_'); expect($result->json('data.movement.id'))->toStartWith('mov_');
    $this->postJson(receiptUrl($product), $input)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.batch.id', $result->json('data.batch.id'));
    $this->postJson(receiptUrl($product), [...$input, 'qty' => '99'])->assertStatus(409)->assertJsonPath('error.code', 'OPERATION_ID_REUSED');
    $this->getJson(receiptUrl($product).'?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.batches.0.cost_price', 800);
    $this->context->run($this->alpha, function () {
        expect(\App\Models\ProductBatch::count())->toBe(1); expect(\App\Models\StockMovement::count())->toBe(1);
        $movement = \App\Models\StockMovement::firstOrFail(); expect($movement->device_id)->not->toBeNull();
        expect(fn () => $movement->update(['qty_after_atoms' => 1]))->toThrow(LogicException::class);
        expect(fn () => $movement->delete())->toThrow(LogicException::class);
        expect(Activity::where('event', 'stock_received')->count())->toBe(1);
    });
});

it('uses Tanzania expiry-day boundaries and rejects expired deliveries without writes while keeping committed retries valid', function () {
    $expiryDate = now('Africa/Dar_es_Salaam')->toDateString();
    $boundary = \Carbon\CarbonImmutable::now('Africa/Dar_es_Salaam')->startOfDay()->addDay()->utc();
    $this->travelTo($boundary->subSecond());
    $product = receiptProduct($this); $today = receiptInput(['expiry_date' => $expiryDate]);
    $this->postJson(receiptUrl($product), receiptInput(['expiry_date' => $boundary->subDays(2)->toDateString()]))->assertStatus(400)->assertJsonPath('error.code', 'EXPIRED_STOCK');
    $this->postJson(receiptUrl($product), receiptInput(['expiry_date' => null]))->assertUnprocessable();
    $this->postJson(receiptUrl($product), $today)->assertCreated()->assertJsonPath('data.batch.sellable', true);
    $this->travelTo($boundary);
    $this->getJson(receiptUrl($product))->assertOk()->assertJsonPath('data.batches.0.sellable', false);
    $this->postJson(receiptUrl($product), $today)->assertOk()->assertJsonPath('data.replayed', true);
    $this->postJson(receiptUrl($product), receiptInput(['expiry_date' => $expiryDate]))->assertStatus(400);
    $this->context->run($this->alpha, fn () => expect(\App\Models\ProductBatch::count())->toBe(1));
    $this->travelBack();
});

it('receives whole cartons and gram packs with snapshots surviving effective packaging edits', function () {
    $product = receiptProduct($this); $packUrl = 'http://alpha.bizismart.test/api/v1/products/'.$product.'/packagings';
    $pack = $this->postJson($packUrl, ['name' => 'Carton', 'base_qty' => '24', 'selling_price' => 25000])->assertCreated()->json('data.id');
    $input = receiptInput(['qty' => '10', 'packaging_id' => $pack]);
    $this->postJson(receiptUrl($product), $input)->assertCreated()->assertJsonPath('data.batch.qty_remaining', '240')->assertJsonPath('data.batch.receipt.conversion_atoms', '24');
    $this->putJson($packUrl.'/'.$pack, ['base_qty' => '12'])->assertOk();
    $this->postJson(receiptUrl($product), $input)->assertOk()->assertJsonPath('data.batch.receipt.packaging_id', $pack)->assertJsonPath('data.batch.receipt.conversion_atoms', '24');
    $this->postJson(receiptUrl($product), receiptInput(['qty' => '1', 'packaging_id' => $pack]))->assertStatus(409)->assertJsonPath('error.code', 'PACKAGING_VERSION_CHANGED');
    $weighted = receiptProduct($this, ['name' => 'Rice', 'barcode' => null, 'quantity_type' => 'weight', 'unit' => 'kg']);
    $weightedPack = $this->postJson('http://alpha.bizismart.test/api/v1/products/'.$weighted.'/packagings', ['name' => '500 g bag', 'base_qty' => '0.500', 'selling_price' => 2100])->assertCreated()->json('data.id');
    $this->postJson(receiptUrl($weighted), receiptInput(['qty' => '10', 'packaging_id' => $weightedPack]))->assertCreated()->assertJsonPath('data.batch.qty_remaining', '5');
    $base = receiptInput(['qty' => '0.750']);
    $this->postJson(receiptUrl($weighted), $base)->assertCreated()->assertJsonPath('data.batch.qty_remaining', '0.75');
    $this->postJson(receiptUrl($weighted), [...$base, 'qty' => '0.75'])->assertOk()->assertJsonPath('data.replayed', true);
    $this->postJson(receiptUrl($weighted), receiptInput(['packaging_id' => $weightedPack, 'qty' => (string) PHP_INT_MAX]))->assertUnprocessable();
    $this->postJson(receiptUrl($weighted), receiptInput(['packaging_id' => $pack]))->assertNotFound();
});

it('rejects malformed receipts, forged balance authority and inactive products without changing catalogue prices', function () {
    $product = receiptProduct($this);
    foreach (['0', '-1', '0.5', ' 100 ', '1e2', 100, (string) PHP_INT_MAX.'0'] as $qty) {
        $this->postJson(receiptUrl($product), receiptInput(['qty' => $qty]))->assertUnprocessable();
    }
    foreach ([['business_id' => 99], ['received_at' => now()->toISOString()], ['is_quarantined' => true], ['qty_remaining_atoms' => 100], ['cost_price' => '800'], ['cost_price' => 9007199254740992], ['expiry_date' => '2027-02-30']] as $extra) {
        $this->postJson(receiptUrl($product), receiptInput($extra))->assertUnprocessable();
    }
    $this->putJson('http://alpha.bizismart.test/api/v1/products/'.$product, ['is_active' => false])->assertOk();
    $this->postJson(receiptUrl($product), receiptInput())->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_INACTIVE');
    $this->context->run($this->alpha, function () { expect(\App\Models\ProductBatch::count())->toBe(0); expect(Product::first()->buying_price)->toBe(800); });
});

it('keeps receipt permissions and foreign tenant links fail-closed including costs and movement ownership', function () {
    $product = receiptProduct($this);
    foreach (['manager', 'cashier'] as $role) {
        $this->withToken($this->{'alpha'.ucfirst($role).'Token'});
        $this->getJson(receiptUrl($product))->assertForbidden(); $this->postJson(receiptUrl($product), receiptInput())->assertForbidden();
    }
    $this->withToken($this->alphaStockkeeperToken);
    $this->postJson(receiptUrl($product), receiptInput())->assertCreated();
    $this->context->run($this->alpha, function () {
        $role = \App\Models\Role::where('name', 'stockkeeper')->firstOrFail(); $role->revokePermissionTo('view_buying_price');
    });
    $this->getJson(receiptUrl($product))->assertOk()->assertJsonMissingPath('data.batches.0.cost_price');
    $this->withToken($this->betaOwnerToken);
    $this->getJson(receiptUrl($product, 'beta'))->assertNotFound(); $this->postJson(receiptUrl($product, 'beta'), receiptInput())->assertNotFound();
    $foreign = $this->context->run($this->beta, fn () => $this->betaOwner->id);
    $this->context->run($this->alpha, function () use ($foreign) {
        $batch = \App\Models\ProductBatch::firstOrFail();
        expect(fn () => \App\Models\StockMovement::create(['product_id' => $batch->product_id, 'batch_id' => $batch->id, 'type' => 'receive', 'qty_change_atoms' => 1, 'qty_after_atoms' => 101,
            'user_id' => $foreign, 'operation_id' => (string) \Illuminate\Support\Str::uuid(), 'receipt' => []]))->toThrow(QueryException::class);
    });
    $this->withToken('invalid'); $this->getJson(receiptUrl($product))->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
});

it('rolls back batch creation and balance when writing its ledger fails', function () {
    $product = receiptProduct($this);
    \App\Models\StockMovement::creating(fn () => throw new RuntimeException('Test ledger failure'));
    try {
        $this->postJson(receiptUrl($product), receiptInput())->assertStatus(500);
        $this->context->run($this->alpha, function () {
            expect(\App\Models\ProductBatch::count())->toBe(0); expect(\App\Models\StockMovement::count())->toBe(0);
            expect(Activity::where('event', 'stock_received')->count())->toBe(0);
        });
    } finally { \App\Models\StockMovement::flushEventListeners(); \App\Models\StockMovement::clearBootedModels(); }
});

it('summarizes eligible and held grams with inclusive Tanzania dates without writing movements', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-10 23:59:59', 'Africa/Dar_es_Salaam'));
    $id = receiptProduct($this, ['quantity_type' => 'weight', 'unit' => 'kg']);
    foreach ([['0.750','2026-10-10'], ['0.250','2026-10-11'], ['1','2026-11-10'], ['2','2026-10-11'], ['3','2026-10-11']] as $i => [$qty,$expiry]) {
        $batch = $this->postJson(receiptUrl($id), receiptInput(['qty'=>$qty,'expiry_date'=>$expiry]))->assertCreated()->json('data.batch.id');
        if ($i > 2) { $this->context->run($this->alpha, fn () => (new \App\Models\ProductBatch)->resolveRouteBinding($batch)->update([$i === 3 ? 'is_quarantined' : 'is_recalled'=>true])); }
    }
    $root='http://alpha.bizismart.test/api/v1/';
    $this->getJson($root.'products/'.$id)->assertOk()->assertJsonPath('data.stock','2')->assertJsonPath('data.stock_total','7')->assertJsonPath('data.nearest_expiry','2026-10-10');
    $this->getJson($root.'stock/expiry-alerts?days=0')->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.batches.0.qty_remaining','0.75')->assertJsonPath('data.batches.0.unit','kg');
    $this->getJson($root.'stock/expiry-alerts?days=1&per_page=1&page=2')->assertOk()->assertJsonPath('meta.total',2)->assertJsonPath('data.batches.0.expiry_date','2026-10-11');
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-11 00:00:00', 'Africa/Dar_es_Salaam'));
    $this->getJson($root.'products/'.$id)->assertOk()->assertJsonPath('data.stock','1.25')->assertJsonPath('data.stock_total','7')->assertJsonPath('data.nearest_expiry','2026-10-11');
    $this->getJson($root.'products?expiring_in=0')->assertOk()->assertJsonPath('meta.total',1);
    $this->getJson($root.'stock/expiry-alerts?days=30')->assertOk()->assertJsonPath('meta.total',2);
    $this->context->run($this->alpha, fn () => expect(\App\Models\StockMovement::count())->toBe(5)); $this->travelBack();
});

it('combines exact low-stock equality and no-batch filters before pagination and excludes disabled archived alerts', function () {
    $a=receiptProduct($this,['name'=>'A Water']); $this->postJson(receiptUrl($a),receiptInput(['qty'=>'50']))->assertCreated();
    $b=receiptProduct($this,['name'=>'B Water','barcode'=>null]);
    $c=receiptProduct($this,['name'=>'C Rice','barcode'=>null,'category'=>'Food']); $this->postJson(receiptUrl($c),receiptInput(['qty'=>'51']))->assertCreated();
    $root='http://alpha.bizismart.test/api/v1/';
    $this->getJson($root.'products?low_stock=1&per_page=1&page=2')->assertOk()->assertJsonPath('meta.total',2)->assertJsonPath('meta.low_stock_matches',2)->assertJsonPath('data.products.0.id',$b)->assertJsonPath('data.products.0.stock','0')->assertJsonPath('data.products.0.nearest_expiry',null);
    $this->getJson($root.'products?low_stock=0')->assertOk()->assertJsonPath('meta.total',3)->assertJsonPath('meta.low_stock_matches',2);
    $this->getJson($root.'products?low_stock=1&category=Food')->assertOk()->assertJsonPath('meta.total',0);
    $this->getJson($root.'products?low_stock=1&expiring_in=30&barcode=06123456789012')->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.products.0.id',$a);
    $this->putJson($root.'products/'.$a,['is_active'=>false])->assertOk()->assertJsonPath('data.stock','50');
    $this->getJson($root.'stock/reorder-alerts')->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.products.0.id',$b);
    $this->getJson($root.'stock/expiry-alerts')->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.batches.0.product_id',$c);
    $this->deleteJson($root.'products/'.$c)->assertOk(); $this->getJson($root.'stock/expiry-alerts')->assertOk()->assertJsonPath('meta.total',0);
    foreach (['low_stock=2','expiring_in=-1','expiring_in=366','expiring_in=0.5'] as $q) { $this->getJson($root.'products?'.$q)->assertUnprocessable(); }
    foreach (['days=-1','days=366','per_page=101','page=0'] as $q) { $this->getJson($root.'stock/expiry-alerts?'.$q)->assertUnprocessable(); }
});

it('isolates summaries and alerts and restricts held stock costs and filters to stock staff', function () {
    $id=receiptProduct($this); $this->postJson(receiptUrl($id),receiptInput(['qty'=>'20']))->assertCreated(); $root='http://alpha.bizismart.test/api/v1/';
    foreach (['manager','cashier'] as $role) {
        $this->withToken($this->{'alpha'.ucfirst($role).'Token'});
        $this->getJson($root.'products')->assertOk()->assertJsonPath('data.products.0.stock','20')->assertJsonMissingPath('data.products.0.stock_total')->assertJsonMissingPath('data.products.0.buying_price')->assertJsonMissingPath('meta.low_stock_matches');
        $this->getJson($root.'products/'.$id)->assertOk()->assertJsonMissingPath('data.stock_total');
        foreach (['products?low_stock=0','products?expiring_in=30','stock/reorder-alerts','stock/expiry-alerts'] as $path) { $this->getJson($root.$path)->assertForbidden()->assertHeader('Cache-Control','no-store, private'); }
    }
    $this->withToken($this->betaOwnerToken);
    foreach (['products?low_stock=1','products?expiring_in=30','stock/reorder-alerts','stock/expiry-alerts'] as $path) { $this->getJson(str_replace('alpha.','beta.',$root).$path)->assertOk()->assertJsonPath('meta.total',0); }
    $this->getJson(str_replace('alpha.','beta.',$root).'products/'.$id)->assertNotFound();
    $this->withToken($this->alphaStockkeeperToken); $this->context->run($this->alpha, fn () => \App\Models\Role::where('name','stockkeeper')->firstOrFail()->revokePermissionTo('view_buying_price'));
    $this->getJson($root.'stock/expiry-alerts')->assertOk()->assertJsonMissingPath('data.batches.0.cost_price');
    $this->withToken('invalid')->getJson($root.'stock/reorder-alerts')->assertUnauthorized()->assertHeader('Cache-Control','no-store, private');
});

it('keeps carton shared stock and huge integer aggregates exact and rejects overflowing receipts atomically', function () {
    $id=receiptProduct($this,['tracks_expiry'=>false]); $root='http://alpha.bizismart.test/api/v1/products/';
    $pack=$this->postJson($root.$id.'/packagings',['name'=>'Carton','base_qty'=>'24','selling_price'=>20000])->assertCreated()->json('data.id');
    $this->postJson(receiptUrl($id),receiptInput(['qty'=>'2','packaging_id'=>$pack,'expiry_date'=>null]))->assertCreated();
    $this->postJson(receiptUrl($id),receiptInput(['qty'=>(string)(PHP_INT_MAX-48),'expiry_date'=>null]))->assertCreated();
    $this->getJson($root.$id)->assertOk()->assertJsonPath('data.stock',(string)PHP_INT_MAX)->assertJsonPath('data.stock_total',(string)PHP_INT_MAX);
    $this->postJson(receiptUrl($id),receiptInput(['qty'=>'1','expiry_date'=>null]))->assertUnprocessable()->assertJsonPath('error.code','VALIDATION_ERROR');
    $this->context->run($this->alpha,function () { expect(\App\Models\ProductBatch::count())->toBe(2); expect(\App\Models\StockMovement::count())->toBe(2); });
    $weighted=receiptProduct($this,['name'=>'Rice','barcode'=>null,'quantity_type'=>'weight','unit'=>'kg']);
    foreach (['0.001','0.750'] as $qty) { $this->postJson(receiptUrl($weighted),receiptInput(['qty'=>$qty]))->assertCreated(); }
    $this->getJson($root.$weighted)->assertOk()->assertJsonPath('data.stock','0.751');
});

it('formats signed held aggregates and fails closed on unrepresentable stored summaries', function () {
    receiptProduct($this);
    $this->context->run($this->alpha, function () {
        // Read-boundary fixtures only: no balance or ledger mutation is performed.
        $product = Product::firstOrFail(); $product->setAttribute('stock_atoms', '0'); $product->setAttribute('held_atoms', '-9223372036854775808'); $product->setAttribute('eligible_expiry', null);
        $stock = app(\App\Services\StockService::class);
        expect($stock->summary($product))->toBe(['stock'=>'0','stock_total'=>'-9223372036854775808','nearest_expiry'=>null]);
        $product->setAttribute('stock_atoms', '9223372036854775808');
        try { $stock->summary($product); $this->fail('Unsafe aggregate must fail.'); }
        catch (\App\Exceptions\ApiException $e) { expect($e->errorCode)->toBe('STOCK_QUANTITY_OVERFLOW'); }
        expect(\App\Models\StockMovement::count())->toBe(0);
    });
});


it('reads exact movement history with immutable pack snapshots, tied ordering, pagination and duplicate receipts', function () {
    $this->freezeTime();
    $id = receiptProduct($this, ['quantity_type' => 'weight', 'unit' => 'kg']);
    $root = 'http://alpha.bizismart.test/api/v1/products/'.$id;
    $pack = $this->postJson($root.'/packagings', ['name' => '500 g bag', 'base_qty' => '0.500', 'selling_price' => 2100])->assertCreated()->json('data.id');
    $input = receiptInput(['qty' => '10', 'packaging_id' => $pack]);
    $first = $this->postJson(receiptUrl($id), $input)->assertCreated()->json('data');
    $this->putJson($root.'/packagings/'.$pack, ['selling_price' => 2200])->assertOk();
    $second = $this->postJson(receiptUrl($id), receiptInput(['qty' => '0.001']))->assertCreated()->json('data');
    $this->postJson(receiptUrl($id), $input)->assertOk()->assertJsonPath('data.replayed', true);
    $this->getJson($root.'/movements?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.movements.0.id', $second['movement']['id'])->assertJsonPath('data.movements.0.qty_change', '0.001')->assertHeader('Cache-Control', 'no-store, private');
    $row = $this->getJson($root.'/movements?per_page=1&page=2')->assertOk()->assertJsonPath('data.movements.0.id', $first['movement']['id'])
        ->assertJsonPath('data.movements.0.qty_change', '5')->assertJsonPath('data.movements.0.qty_after', '5')
        ->assertJsonPath('data.movements.0.receipt.packaging_id', $pack)->assertJsonPath('data.movements.0.receipt.packaging_version', 1)->assertJsonPath('data.movements.0.receipt.conversion_atoms', '500')
        ->assertJsonMissingPath('data.movements.0.business_id')->assertJsonMissingPath('data.movements.0.cost_price')->assertJsonMissingPath('data.movements.0.phone')->assertJsonMissingPath('data.movements.0.qty_change_atoms')->json('data.movements.0');
    expect($row['user_id'])->toStartWith('usr_'); expect($row['device_id'])->toStartWith('dev_'); expect($row['created_at'])->toBe(now()->startOfSecond()->toISOString());
    $this->getJson($root.'/movements?batch_id='.$first['batch']['id'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.movements.0.batch_id', $first['batch']['id']);
    $this->getJson($root.'/movements?page=3&per_page=1')->assertOk()->assertJsonCount(0, 'data.movements');
    $this->context->run($this->alpha, function () { expect(\App\Models\StockMovement::count())->toBe(2); expect(\App\Models\ProductBatch::sum('qty_remaining_atoms'))->toBe('5001'); });
    $this->travelBack();
});

it('keeps movement routes and batch filters tenant and product scoped with restricted roles and public IDs', function () {
    $id = receiptProduct($this);
    $batch = $this->postJson(receiptUrl($id), receiptInput())->assertCreated()->json('data.batch.id');
    $other = receiptProduct($this, ['name' => 'Other', 'barcode' => null]);
    $root = 'http://alpha.bizismart.test/api/v1/products/';
    foreach (['manager', 'cashier'] as $role) {
        $this->withToken($this->{'alpha'.ucfirst($role).'Token'})->getJson($root.$id.'/movements')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
    }
    $this->withToken($this->alphaStockkeeperToken)->getJson($root.$id.'/movements')->assertOk()->assertJsonPath('data.movements.0.qty_change', '100');
    $this->withToken($this->betaOwnerToken);
    $foreign = $this->postJson('http://beta.bizismart.test/api/v1/products', $this->input)->assertCreated()->json('data.id');
    $this->getJson('http://beta.bizismart.test/api/v1/products/'.$id.'/movements')->assertNotFound();
    $this->getJson('http://beta.bizismart.test/api/v1/products/'.$foreign.'/movements?batch_id='.$batch)->assertNotFound();
    $this->getJson('http://beta.bizismart.test/api/v1/products/'.$foreign.'/movements')->assertOk()->assertJsonPath('meta.total', 0);
    $this->withToken($this->alphaOwnerToken)->getJson($root.$other.'/movements?batch_id='.$batch)->assertNotFound();
    foreach (['batch_id=1', 'batch_id=bad', 'per_page=101', 'page=0', 'per_page=0'] as $query) { $this->getJson($root.$id.'/movements?'.$query)->assertUnprocessable(); }
    $this->putJson($root.$id, ['is_active' => false])->assertOk(); $this->getJson($root.$id.'/movements')->assertOk()->assertJsonPath('meta.total', 1);
    $this->postJson($root.$id.'/movements', ['qty_change' => '-3'])->assertStatus(405);
    $this->deleteJson($root.$id)->assertOk(); $this->getJson($root.$id.'/movements')->assertNotFound();
    $this->withToken('invalid')->getJson($root.$other.'/movements')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
    $this->context->run($this->alpha, fn () => expect(\App\Models\StockMovement::count())->toBe(1));
});

it('fails closed for retained products and unscoped ledger queries outside their tenant', function () {
    $id = receiptProduct($this); $this->postJson(receiptUrl($id), receiptInput())->assertCreated();
    $product = $this->context->run($this->alpha, fn () => Product::firstOrFail());
    $this->context->clear(); expect(\App\Models\StockMovement::count())->toBe(0);
    $this->context->run($this->beta, fn () => expect(fn () => app(\App\Services\StockService::class)->movements($product))->toThrow(LogicException::class));
});

it('serializes concurrent duplicate receipts across independent MySQL processes', function () {
    $publicId = receiptProduct($this);
    $businessId = $this->alpha->id; $actorId = $this->alphaOwner->id;
    $productId = $this->context->run($this->alpha, fn () => Product::firstOrFail()->id); $input = receiptInput();
    // Separate connections must see committed fixtures. This remains confined to the guarded *_test database.
    \Illuminate\Support\Facades\DB::commit();
    \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
    $receive = concurrentReceiptJob($businessId, $actorId, $productId, $input);
    $results = \Illuminate\Support\Facades\Concurrency::driver('process')->run([$receive, $receive]);
    expect($results[0]['id'])->toBe($results[1]['id']); expect(array_column($results, 'replayed'))->toContain(true, false);
    $this->context->run($this->alpha, function () {
        expect(\App\Models\ProductBatch::count())->toBe(1); expect(\App\Models\StockMovement::count())->toBe(1);
        expect(\App\Models\ProductBatch::sum('qty_remaining_atoms'))->toBe(\App\Models\StockMovement::sum('qty_change_atoms'));
    });
});
