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
            $device = Device::create(['device_uid' => 'catalogue-device', 'name' => 'Test device', 'status' => 'approved']);
            foreach (['owner', 'manager', 'cashier', 'stockkeeper'] as $index => $role) {
                $user = User::create(['name' => 'Test '.$role, 'phone' => '071000000'.$index, 'pin_hash' => '1234', 'must_change_pin' => false]);
                app(StaffRoleService::class)->assign($user, $role);
                if ($role === 'owner') {
                    $user->totp_secret = 'JBSWY3DPEHPK3PXP'; $user->totp_confirmed_at = now(); $user->save();
                }
                $token = $user->createToken('catalogue-test', ['*'], now()->addDays(1));
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

function catalogueUrl(string $suffix = '', string $shop = 'alpha'): string { return 'http://'.$shop.'.bizismart.test/api/v1/products'.$suffix; }

function departmentUrl(string $suffix = '', string $shop = 'alpha'): string { return 'http://'.$shop.'.bizismart.test/api/v1/departments'.$suffix; }

it('manages real departments, rejects duplicates atomically, reserves links and frees archived names', function () {
    $id = $this->postJson(departmentUrl(), ['name' => ' Grocery '])->assertCreated()->assertJsonPath('data.name', 'Grocery')
        ->assertJsonPath('data.assigned_products_count', 0)->assertJsonMissingPath('data.business_id')->json('data.id');
    expect($id)->toStartWith('dep_');
    $this->postJson(departmentUrl(), ['name' => 'grocery'])->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_DEPARTMENT');
    $other = $this->postJson(departmentUrl(), ['name' => 'Drinks'])->assertCreated()->json('data.id');
    $this->putJson(departmentUrl('/'.$other), ['name' => 'Grocery'])->assertStatus(409);
    $this->getJson(departmentUrl('?search=Drinks'))->assertOk()->assertJsonPath('data.departments.0.name', 'Drinks');
    $this->putJson(departmentUrl('/'.$id), ['name' => 'Food'])->assertOk()->assertJsonPath('data.name', 'Food');
    $this->getJson(departmentUrl('?per_page=1&page=2'))->assertOk()->assertJsonCount(1, 'data.departments')->assertJsonPath('meta.total', 2);
    $this->getJson(departmentUrl('?search=%25'))->assertOk()->assertJsonCount(0, 'data.departments');
    $this->deleteJson(departmentUrl('/'.$id))->assertOk()->assertJsonPath('data.completed', true);
    $this->putJson(departmentUrl('/'.$id), ['name' => 'Gone'])->assertNotFound();
    $next = $this->postJson(departmentUrl(), ['name' => 'Food'])->assertCreated()->json('data.id');
    expect($next)->not->toBe($id);
    $this->context->run($this->alpha, function () {
        expect(\App\Models\Department::withTrashed()->count())->toBe(3);
        expect(Activity::where('event', 'department_updated')->firstOrFail()->properties['old']['name'])->toBe('Grocery');
        expect(Activity::where('event', 'department_archived')->firstOrFail()->properties['device_id'])->toStartWith('dev_');
    });
});

it('assigns and filters departments independently of category and blocks archive until every product is cleared', function () {
    $id = $this->postJson(departmentUrl(), ['name' => 'Food'])->assertCreated()->json('data.id');
    $product = $this->postJson(catalogueUrl(), [...$this->input, 'department_id' => $id])->assertCreated()
        ->assertJsonPath('data.department_id', $id)->assertJsonPath('data.department_name', 'Food')->assertJsonPath('data.category', 'Beverages')->json('data.id');
    $this->deleteJson(departmentUrl('/'.$id))->assertStatus(409)->assertJsonPath('error.code', 'DEPARTMENT_IN_USE');
    $this->getJson(catalogueUrl('?department_id='.$id))->assertOk()->assertJsonPath('data.products.0.id', $product);
    $this->putJson(catalogueUrl('/'.$product), ['selling_price' => 1400])->assertOk()->assertJsonPath('data.department_id', $id);
    $this->putJson(catalogueUrl('/'.$product), ['is_active' => false])->assertOk();
    $this->deleteJson(departmentUrl('/'.$id))->assertStatus(409);
    $this->putJson(catalogueUrl('/'.$product), ['department_id' => null])->assertOk()->assertJsonPath('data.department_id', null);
    $this->getJson(catalogueUrl('?department_id='.$id))->assertOk()->assertJsonCount(0, 'data.products');
    $this->deleteJson(departmentUrl('/'.$id))->assertOk();
    $this->putJson(catalogueUrl('/'.$product), ['department_id' => $id, 'selling_price' => 1])->assertNotFound();
    $this->getJson(catalogueUrl('/'.$product))->assertOk()->assertJsonPath('data.selling_price', 1400);
    $this->getJson(catalogueUrl('?department_id='.$id))->assertNotFound();
});

it('retains archived product department attribution and blocks its department archive', function () {
    $id = $this->postJson(departmentUrl(), ['name' => 'Historical'])->assertCreated()->json('data.id');
    $product = $this->postJson(catalogueUrl(), [...$this->input, 'department_id' => $id])->assertCreated()->json('data.id');
    $this->deleteJson(catalogueUrl('/'.$product))->assertOk();
    $this->deleteJson(departmentUrl('/'.$id))->assertStatus(409);
    $this->getJson(departmentUrl())->assertOk()->assertJsonPath('data.departments.0.assigned_products_count', 1);
});

it('isolates department IDs and metadata by tenant, rejects forged fields and respects the approved role matrix', function () {
    $id = $this->postJson(departmentUrl(), ['name' => 'Alpha department'])->assertCreated()->json('data.id');
    foreach ([['name' => '  '], ['name' => str_repeat('x', 161)], ['name' => 'Test', 'business_id' => 1], ['name' => 'Test', 'current_name' => 'FORGED']] as $invalid) {
        $this->postJson(departmentUrl(), $invalid)->assertUnprocessable();
    }
    foreach (['manager', 'cashier'] as $role) {
        $this->withToken($this->{'alpha'.ucfirst($role).'Token'});
        $this->getJson(departmentUrl())->assertOk()->assertJsonMissingPath('data.departments.0.assigned_products_count')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson(departmentUrl(), ['name' => 'Denied'])->assertForbidden();
        $this->putJson(departmentUrl('/'.$id), ['name' => 'Denied'])->assertForbidden();
        $this->deleteJson(departmentUrl('/'.$id))->assertForbidden();
    }
    $this->withToken($this->alphaStockkeeperToken);
    $this->putJson(departmentUrl('/'.$id), ['name' => 'Stockkeeper update'])->assertOk();
    $this->withToken($this->betaOwnerToken);
    $this->postJson(departmentUrl('', 'beta'), ['name' => 'Stockkeeper update'])->assertCreated();
    $this->putJson(departmentUrl('/'.$id, 'beta'), ['name' => 'Foreign'])->assertNotFound();
    $this->postJson(catalogueUrl('', 'beta'), [...$this->input, 'department_id' => $id])->assertNotFound();
    $this->getJson(catalogueUrl('?department_id='.$id, 'beta'))->assertNotFound();
    $this->withToken('invalid');
    $this->getJson(departmentUrl())->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
});

it('enforces composite department ownership in MySQL even when bypassing request validation', function () {
    $foreign = $this->context->run($this->beta, fn () => \App\Models\Department::create(['name' => 'Beta', 'current_name' => 'Beta']));
    $this->postJson(catalogueUrl(), $this->input)->assertCreated();
    $this->context->run($this->alpha, function () use ($foreign) {
        $product = Product::firstOrFail(); $product->department_id = $foreign->id;
        expect(fn () => $product->save())->toThrow(QueryException::class);
        expect($product->fresh()->department_id)->toBeNull();
    });
});

it('creates shared-stock carton metadata, resolves packaging barcodes and versions edits immutably', function () {
    $product = $this->postJson(catalogueUrl(), $this->input)->assertCreated()->json('data.id');
    $url = catalogueUrl('/'.$product.'/packagings');
    $pack = $this->postJson($url, ['name' => 'Carton', 'base_qty' => '24', 'selling_price' => 25000, 'barcode' => 'CARTON'])
        ->assertCreated()->assertJsonPath('data.base_qty', '24')->assertJsonPath('data.conversion_atoms', '24')->assertJsonPath('data.product_id', $product)->json('data.id');
    expect($pack)->toStartWith('pkg_');
    $this->getJson(catalogueUrl('?barcode=CARTON'))->assertOk()->assertJsonPath('data.products.0.selected_packaging.id', $pack)
        ->assertJsonPath('data.products.0.selected_packaging.selling_price', 25000)->assertJsonPath('data.products.0.stock', '0');
    $next = $this->putJson($url.'/'.$pack, ['base_qty' => '12', 'selling_price' => 14000])->assertOk()
        ->assertJsonPath('data.version', 2)->assertJsonPath('data.base_qty', '12')->json('data.id');
    expect($next)->not->toBe($pack);
    $this->putJson($url.'/'.$pack, ['selling_price' => 1])->assertStatus(409)->assertJsonPath('error.code', 'PACKAGING_VERSION_CHANGED');
    $this->putJson($url.'/'.$next, ['selling_price' => 14000])->assertOk()->assertJsonPath('data.id', $next)->assertJsonPath('data.version', 2);
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.packagings')->assertJsonPath('data.packagings.0.id', $next);
    $this->context->run($this->alpha, function () use ($pack) {
        $old = \App\Models\ProductPackaging::where('public_id', substr($pack, 4))->firstOrFail();
        expect($old->conversion_atoms)->toBe(24)->and($old->selling_price)->toBe(25000)->and($old->archived_at)->not->toBeNull();
        expect(Activity::where('event', 'packaging_version_created')->firstOrFail()->properties['device_id'])->toStartWith('dev_');
    });
    $this->deleteJson($url.'/'.$next)->assertOk();
    $this->getJson(catalogueUrl('?barcode=CARTON'))->assertOk()->assertJsonCount(0, 'data.products');
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.packagings');
});

it('enforces one barcode registry across base products and all packaging families with rollback', function () {
    $id = $this->postJson(catalogueUrl(), $this->input)->assertCreated()->json('data.id');
    $url = catalogueUrl('/'.$id.'/packagings');
    $body = ['name' => 'Pack', 'base_qty' => '6', 'selling_price' => 6000, 'barcode' => 'PACK'];
    $pack = $this->postJson($url, $body)->assertCreated()->json('data.id');
    $this->postJson($url, [...$body, 'barcode' => $this->input['barcode']])->assertStatus(409);
    $this->postJson($url, $body)->assertStatus(409);
    $this->putJson(catalogueUrl('/'.$id), ['barcode' => 'PACK'])->assertStatus(409);
    $this->putJson($url.'/'.$pack, ['barcode' => $this->input['barcode'], 'base_qty' => '10'])->assertStatus(409);
    $this->getJson($url)->assertOk()->assertJsonPath('data.packagings.0.base_qty', '6')->assertJsonPath('data.packagings.0.id', $pack);
    $next = $this->putJson($url.'/'.$pack, ['barcode' => 'NEWPACK'])->assertOk()->json('data.id');
    $this->getJson(catalogueUrl('?barcode=PACK'))->assertOk()->assertJsonCount(0, 'data.products');
    $this->postJson($url, $body)->assertStatus(409);
    $reclaimed = $this->putJson($url.'/'.$next, ['barcode' => 'PACK'])->assertOk()->json('data.id');
    $this->getJson(catalogueUrl('?barcode=PACK'))->assertOk()->assertJsonPath('data.products.0.selected_packaging.id', $reclaimed);
    $this->deleteJson($url.'/'.$reclaimed)->assertOk();
    $this->postJson($url, $body)->assertStatus(409);
    $this->putJson(catalogueUrl('/'.$id), ['barcode' => 'PACK'])->assertStatus(409);
});

it('validates gram packs and rejects fractional counted factors, zero, overflow, numeric strings and authority inputs', function () {
    $weighted = $this->postJson(catalogueUrl(), [...$this->input, 'quantity_type' => 'weight', 'unit' => 'kg'])->assertCreated()->json('data.id');
    $url = catalogueUrl('/'.$weighted.'/packagings');
    $body = ['name' => '500 g pack', 'base_qty' => '0.500', 'selling_price' => 2000];
    $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.base_qty', '0.5')->assertJsonPath('data.conversion_atoms', '500');
    foreach (['0', '0.0001', '9223372036854775.808', ' 0.5 ', '1e3', 0.5] as $invalid) {
        $this->postJson($url, [...$body, 'base_qty' => $invalid])->assertUnprocessable();
    }
    foreach ([['selling_price' => '2000'], ['selling_price' => 1.5], ['selling_price' => 9007199254740992], ['conversion_atoms' => '500'], ['business_id' => 1], ['version' => 2]] as $change) {
        $this->postJson($url, [...$body, ...$change])->assertUnprocessable();
    }
    $counted = $this->postJson(catalogueUrl(), [...$this->input, 'barcode' => 'COUNTED'])->assertCreated()->json('data.id');
    $this->postJson(catalogueUrl('/'.$counted.'/packagings'), $body)->assertUnprocessable();
});

it('isolates pack ownership, permits stockkeepers, denies manager writes and hides inactive packs from sellers', function () {
    $product = $this->postJson(catalogueUrl(), $this->input)->assertCreated()->json('data.id');
    $other = $this->postJson(catalogueUrl(), [...$this->input, 'barcode' => 'OTHER'])->assertCreated()->json('data.id');
    $url = catalogueUrl('/'.$product.'/packagings');
    $body = ['name' => 'Box', 'base_qty' => '12', 'selling_price' => 12000, 'barcode' => 'BOX', 'is_active' => false];
    $this->withToken($this->alphaStockkeeperToken);
    $pack = $this->postJson($url, $body)->assertCreated()->json('data.id');
    $this->putJson(catalogueUrl('/'.$other.'/packagings/'.$pack), ['selling_price' => 1])->assertNotFound();
    $this->withToken($this->alphaManagerToken);
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.packagings');
    $this->postJson($url, $body)->assertForbidden();
    $this->putJson($url.'/'.$pack, ['selling_price' => 1])->assertForbidden();
    $this->deleteJson($url.'/'.$pack)->assertForbidden();
    $this->withToken($this->betaOwnerToken);
    $beta = $this->postJson(catalogueUrl('', 'beta'), $this->input)->assertCreated()->json('data.id');
    $this->putJson(catalogueUrl('/'.$beta.'/packagings/'.$pack, 'beta'), ['selling_price' => 1])->assertNotFound();
    $this->getJson(catalogueUrl('/'.$product.'/packagings', 'beta'))->assertNotFound();
    $this->postJson(catalogueUrl('/'.$beta.'/packagings', 'beta'), $body)->assertCreated();
    $this->withToken($this->alphaOwnerToken);
    $this->deleteJson(catalogueUrl('/'.$product))->assertOk();
    $this->getJson(catalogueUrl('?barcode=BOX'))->assertOk()->assertJsonCount(0, 'data.products');
    $this->postJson($url, $body)->assertNotFound();
});

it('persists counted products with exact quantities, public IDs and real zero stock metadata', function () {
    $response = $this->postJson(catalogueUrl(), $this->input)->assertCreated()->assertJsonPath('data.buying_price', 800)
        ->assertJsonPath('data.reorder_level', '50')->assertJsonPath('data.quantity_precision', 0)
        ->assertJsonPath('data.quantity_scale', 1)->assertJsonPath('data.stock', '0')->assertJsonPath('data.stock_available', true)
        ->assertJsonMissingPath('data.business_id')->assertJsonMissingPath('data.reorder_level_atoms')->assertHeader('Cache-Control', 'no-store, private');
    expect($response->json('data.id'))->toStartWith('prod_');
    $this->getJson(catalogueUrl('/'.$response->json('data.id')))->assertOk()->assertJsonPath('data.barcode', '06123456789012');
    $this->context->run($this->alpha, function () {
        expect(Product::count())->toBe(1)->and(ProductBarcode::count())->toBe(1)
            ->and(Product::first()->reorder_level_atoms)->toBe(50);
        $activity = Activity::where('event', 'product_created')->firstOrFail();
        expect($activity->properties['attributes']['selling_price'])->toBe(1200)
            ->and($activity->properties['device_id'])->toStartWith('dev_');
        expect(Product::first()->toArray())->not->toHaveKey('buying_price');
    });
});

it('uses gram atoms for weighted products and rejects invalid precision, units and type changes', function () {
    $response = $this->postJson(catalogueUrl(), [...$this->input, 'quantity_type' => 'weight', 'unit' => 'kg', 'reorder_level' => '0.750'])
        ->assertCreated()->assertJsonPath('data.reorder_level', '0.75')->assertJsonPath('data.quantity_precision', 3);
    $id = $response->json('data.id');
    $this->context->run($this->alpha, fn () => expect(Product::first()->reorder_level_atoms)->toBe(750));
    $this->putJson(catalogueUrl('/'.$id), ['reorder_level' => '0.0001'])->assertUnprocessable();
    $this->putJson(catalogueUrl('/'.$id), ['reorder_level' => 0.75])->assertUnprocessable();
    $this->putJson(catalogueUrl('/'.$id), ['unit' => 'g'])->assertUnprocessable()->assertJsonPath('error.field', 'unit');
    $this->putJson(catalogueUrl('/'.$id), ['quantity_type' => 'counted'])->assertUnprocessable()->assertJsonPath('error.field', 'quantity_type');
    $this->putJson(catalogueUrl('/'.$id), ['reorder_level' => '9223372036854775.808'])->assertUnprocessable();
    $this->getJson(catalogueUrl('/'.$id))->assertOk()->assertJsonPath('data.reorder_level', '0.75');
});

it('rejects unsafe money, fractional counted units and authority fields', function (array $change) {
    $this->postJson(catalogueUrl(), [...$this->input, ...$change])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
    $this->context->run($this->alpha, fn () => expect(Product::count())->toBe(0)->and(ProductBarcode::count())->toBe(0));
})->with([
    [['selling_price' => 1.5]], [['buying_price' => '800']], [['selling_price' => -1]],
    [['selling_price' => 9007199254740992]], [['reorder_level' => '1.5']], [['business_id' => 123]],
    [['stock' => '100']], [['stock_total' => '100']], [['stock_available' => true]], [['nearest_expiry' => '2027-01-01']],
    [['quantity_scale' => 1]], [['reorder_level_atoms' => 50]], [['department_id' => 'dep_foreign']],
]);

it('reserves changed and archived barcodes, rolls back collisions and permits the same code in another shop', function () {
    $id = $this->postJson(catalogueUrl(), $this->input)->assertCreated()->json('data.id');
    $second = $this->postJson(catalogueUrl(), [...$this->input, 'name' => 'Second', 'barcode' => 'SECOND'])->assertCreated()->json('data.id');
    $this->postJson(catalogueUrl(), $this->input)->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_BARCODE');
    $this->putJson(catalogueUrl('/'.$second), ['barcode' => $this->input['barcode'], 'selling_price' => 999])->assertStatus(409);
    $this->getJson(catalogueUrl('/'.$second))->assertOk()->assertJsonPath('data.selling_price', 1200)->assertJsonPath('data.barcode', 'SECOND');
    $this->putJson(catalogueUrl('/'.$id), ['barcode' => 'NEW'])->assertOk();
    $this->postJson(catalogueUrl(), $this->input)->assertStatus(409);
    $this->getJson(catalogueUrl().'?barcode='.$this->input['barcode'])->assertOk()->assertJsonPath('meta.total', 0);
    $this->putJson(catalogueUrl('/'.$id), ['barcode' => $this->input['barcode']])->assertOk();
    $this->deleteJson(catalogueUrl('/'.$id))->assertOk()->assertJsonPath('data.completed', true);
    $this->getJson(catalogueUrl('/'.$id))->assertNotFound();
    $this->postJson(catalogueUrl(), $this->input)->assertStatus(409);
    $this->withToken($this->betaOwnerToken)->postJson(catalogueUrl(shop: 'beta'), $this->input)->assertCreated();
    $this->context->run($this->alpha, function () {
        expect(Product::withTrashed()->count())->toBe(2)->and(ProductBarcode::count())->toBe(3);
        expect(Activity::where('event', 'product_created')->count())->toBe(2)
            ->and(Activity::where('event', 'product_archived')->count())->toBe(1);
    });
});

it('hides costs on every cashier and manager read, denies their writes and allows stockkeeper management', function () {
    $id = $this->postJson(catalogueUrl(), $this->input)->assertCreated()->json('data.id');
    foreach (['cashier', 'manager'] as $role) {
        $this->withToken($this->{'alpha'.ucfirst($role).'Token'});
        $this->getJson(catalogueUrl())->assertOk()->assertJsonMissingPath('data.products.0.buying_price');
        $this->getJson(catalogueUrl().'?barcode='.$this->input['barcode'])->assertOk()->assertJsonMissingPath('data.products.0.buying_price');
        $this->getJson(catalogueUrl('/'.$id))->assertOk()->assertJsonMissingPath('data.buying_price');
        $this->postJson(catalogueUrl(), [...$this->input, 'barcode' => 'CASHIER'])->assertForbidden();
        $this->putJson(catalogueUrl('/'.$id), ['selling_price' => 999])->assertForbidden();
        $this->deleteJson(catalogueUrl('/'.$id))->assertForbidden();
    }
    $this->withToken($this->alphaStockkeeperToken)->putJson(catalogueUrl('/'.$id), ['selling_price' => 1500])->assertOk()->assertJsonPath('data.buying_price', 800);
    $this->getJson(catalogueUrl())->assertOk()->assertJsonPath('data.products.0.buying_price', 800);
});

it('isolates list, search, barcode and resource writes across shops and rejects internal IDs', function () {
    $id = $this->postJson(catalogueUrl(), $this->input)->assertCreated()->json('data.id');
    $internal = $this->context->run($this->alpha, fn () => Product::first()->id);
    $this->getJson(catalogueUrl('/'.$internal))->assertNotFound();
    $this->getJson(catalogueUrl('/'.substr($id, 5)))->assertNotFound();
    $this->withToken($this->betaOwnerToken)->getJson(catalogueUrl(shop: 'beta'))->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson(catalogueUrl('?search=Water', 'beta'))->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson(catalogueUrl('?barcode='.$this->input['barcode'], 'beta'))->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson(catalogueUrl('/'.$id, 'beta'))->assertNotFound();
    $this->putJson(catalogueUrl('/'.$id, 'beta'), ['name' => 'Foreign edit'])->assertNotFound();
    $this->deleteJson(catalogueUrl('/'.$id, 'beta'))->assertNotFound();
    $this->getJson(catalogueUrl('/'.$id))->assertUnauthorized();
    $this->withToken('')->getJson(catalogueUrl())->assertUnauthorized();
});

it('filters and paginates the catalogue and treats search wildcards literally', function () {
    $this->postJson(catalogueUrl(), $this->input)->assertCreated();
    $this->postJson(catalogueUrl(), [...$this->input, 'name' => 'Rice 100%', 'category' => 'Food', 'barcode' => null])->assertCreated();
    $this->getJson(catalogueUrl().'?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data.products');
    $this->getJson(catalogueUrl().'?category=Food')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.products.0.name', 'Rice 100%');
    $this->getJson(catalogueUrl().'?search='.urlencode('100%'))->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson(catalogueUrl().'?search=_')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson(catalogueUrl().'?per_page=101')->assertUnprocessable();
    $this->getJson(catalogueUrl().'?low_stock=1')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson(catalogueUrl().'?expiring_in=30')->assertOk()->assertJsonPath('meta.total', 0);
});

it('enforces active product limits on create and reactivation while retaining disabled and archived history', function () {
    $this->plan->limits = ['products' => 1]; $this->plan->save();
    $id = $this->postJson(catalogueUrl(), $this->input)->assertCreated()->json('data.id');
    $this->postJson(catalogueUrl(), [...$this->input, 'barcode' => 'SECOND'])->assertForbidden()->assertJsonPath('error.code', 'PLAN_LIMIT_REACHED');
    $disabled = $this->postJson(catalogueUrl(), [...$this->input, 'barcode' => 'DISABLED', 'is_active' => false])->assertCreated()->json('data.id');
    $this->putJson(catalogueUrl('/'.$disabled), ['is_active' => true])->assertForbidden();
    $this->putJson(catalogueUrl('/'.$id), ['is_active' => false])->assertOk();
    $second = $this->postJson(catalogueUrl(), [...$this->input, 'barcode' => 'SECOND'])->assertCreated()->json('data.id');
    $this->putJson(catalogueUrl('/'.$id), ['is_active' => true])->assertForbidden()->assertJsonPath('error.code', 'PLAN_LIMIT_REACHED');
    $this->getJson(catalogueUrl())->assertOk()->assertJsonPath('meta.total', 3);
    $this->withToken($this->alphaCashierToken)->getJson(catalogueUrl())->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson(catalogueUrl('/'.$id))->assertNotFound();
    $this->withToken($this->alphaOwnerToken)->deleteJson(catalogueUrl('/'.$second))->assertOk();
    $this->putJson(catalogueUrl('/'.$id), ['is_active' => true])->assertOk();
});

it('audits old and new prices without stock mutations', function () {
    $id = $this->postJson(catalogueUrl(), $this->input)->assertCreated()->json('data.id');
    $this->putJson(catalogueUrl('/'.$id), ['selling_price' => 1500, 'buying_price' => 900])->assertOk();
    $this->context->run($this->alpha, function () {
        $event = Activity::where('event', 'product_updated')->firstOrFail();
        expect($event->properties['old']['selling_price'])->toBe(1200)
            ->and($event->properties['attributes']['selling_price'])->toBe(1500)
            ->and($event->properties['old']['buying_price'])->toBe(800);
        expect($event->getRawOriginal('properties'))->not->toContain('selling_price');
    });
    // Receiving now exists; an incomplete receipt must still be rejected without changing stock.
    $this->postJson(catalogueUrl('/'.$id.'/batches'), ['qty' => '100'])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
    $this->context->run($this->alpha, function () {
        expect(\App\Models\ProductBatch::count())->toBe(0); expect(\App\Models\StockMovement::count())->toBe(0);
    });
});

it('fails closed outside a tenant and rejects cross-shop barcode foreign keys and retained models', function () {
    $this->postJson(catalogueUrl(), $this->input)->assertCreated();
    $product = $this->context->run($this->alpha, fn () => Product::first());
    expect(Product::count())->toBe(0)->and(ProductBarcode::count())->toBe(0);
    expect(fn () => Product::create($this->input))->toThrow(LogicException::class);
    $this->context->run($this->beta, function () use ($product) {
        expect(fn () => app(ProductService::class)->save($this->betaOwner, ['name' => 'Foreign'], $product))->toThrow(LogicException::class);
        expect(fn () => ProductBarcode::create(['product_id' => $product->id, 'barcode' => 'FOREIGN']))->toThrow(QueryException::class);
    });
});

it('defaults omitted optional fields and rechecks permissions in the domain service', function () {
    $id = $this->postJson(catalogueUrl(), ['name' => 'Plain item', 'unit' => 'piece', 'buying_price' => 0, 'selling_price' => 0])
        ->assertCreated()->assertJsonPath('data.quantity_type', 'counted')->assertJsonPath('data.reorder_level', '0')
        ->assertJsonPath('data.barcode', null)->assertJsonPath('data.tracks_expiry', false)->assertJsonPath('data.is_active', true)->json('data.id');
    $this->context->run($this->alpha, function () {
        $product = Product::firstOrFail();
        expect(fn () => app(ProductService::class)->save($this->alphaCashier, ['selling_price' => 999], $product))
            ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
        expect(fn () => app(ProductService::class)->archive($this->alphaManager, $product))
            ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
        expect($product->fresh()->selling_price)->toBe(0);
    });
    $this->getJson(catalogueUrl('/'.$id))->assertOk();
});

it('preserves exact catalogue wire values instead of silently trimming malformed input', function () {
    foreach ([' 1', '1 ', '1e3', '01'] as $quantity) {
        $this->postJson(catalogueUrl(), [...$this->input, 'reorder_level' => $quantity])->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private');
    }
    foreach ([' CODE', 'CODE ', 'CO DE'] as $barcode) {
        $this->postJson(catalogueUrl(), [...$this->input, 'barcode' => $barcode])->assertUnprocessable();
    }
    $this->context->run($this->alpha, fn () => expect(Product::count())->toBe(0));
});
