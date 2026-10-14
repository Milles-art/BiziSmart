<?php

use App\Models\{Business, Device, Plan, Product, ProductPriceHistory, User};
use App\Services\StaffRoleService;
use App\Tenancy\TenantContext;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);
beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']); $this->context = app(TenantContext::class);
    $plan = Plan::create(['code' => 'prices', 'name' => 'Prices', 'price_monthly' => 0, 'limits' => ['products' => 10], 'features' => []]);
    foreach (['alpha', 'beta'] as $label) {
        $shop = Business::create(['name' => $label, 'subdomain' => $label, 'plan_id' => $plan->id, 'owner_phone' => '0700000001']); $this->{$label} = $shop;
        $this->context->run($shop, function () use ($label) {
            app(StaffRoleService::class)->provision();
            $device = Device::create(['device_uid' => 'price-test', 'name' => 'Price test', 'status' => 'approved']);
            foreach (['owner', 'cashier', 'stockkeeper'] as $index => $role) {
                $user = User::create(['name' => $role, 'phone' => '071000000'.$index, 'pin_hash' => '1234', 'must_change_pin' => false]);
                app(StaffRoleService::class)->assign($user, $role);
                if ($role === 'owner') { $user->totp_secret = 'JBSWY3DPEHPK3PXP'; $user->totp_confirmed_at = now(); $user->save(); }
                $token = $user->createToken('test', ['*'], now()->addDay()); $token->accessToken->device_id = $device->id; $token->accessToken->save();
                $this->{$label.ucfirst($role).'Token'} = $token->plainTextToken;
            }
        });
    }
    $this->withToken($this->alphaOwnerToken);
    $this->input = ['name' => 'Rice', 'barcode' => 'RICE-1', 'quantity_type' => 'weight', 'unit' => 'kg', 'buying_price' => 2000, 'selling_price' => 4000, 'reorder_level' => '1.000', 'tracks_expiry' => false];
});
afterEach(fn () => $this->context->clear());
function priceTestUrl(string $suffix = '', string $tenant = 'alpha'): string { return 'http://'.$tenant.'.bizismart.test/api/v1/products'.$suffix; }

it('records initial and changed prices exactly once with immutable product and packaging snapshots', function () {
    $id = $this->postJson(priceTestUrl(), $this->input)->assertCreated()->json('data.id');
    $this->putJson(priceTestUrl('/'.$id), ['selling_price' => 5000])->assertOk();
    $this->putJson(priceTestUrl('/'.$id), ['selling_price' => 5000, 'name' => 'Rice renamed'])->assertOk();
    $pack = $this->postJson(priceTestUrl('/'.$id.'/packagings'), ['name' => 'Half kilo', 'base_qty' => '0.500', 'selling_price' => 2300, 'barcode' => 'RICE-HALF'])->assertCreated()->json('data.id');
    $this->putJson(priceTestUrl('/'.$id.'/packagings/'.$pack), ['selling_price' => 2500])->assertOk();
    $this->getJson(priceTestUrl('/'.$id.'/price-history?per_page=2'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('meta.total', 4)->assertJsonPath('data.prices.0.old_price', 2300)->assertJsonPath('data.prices.0.new_price', 2500)
        ->assertJsonPath('data.prices.0.packaging_version', 2)->assertJsonPath('data.prices.0.base_qty', '0.5')
        ->assertJsonPath('data.prices.0.actor_name', 'owner')->assertJsonMissingPath('data.prices.0.user_id')->assertJsonMissingPath('data.prices.0.business_id');
    $this->getJson(priceTestUrl('/'.$id.'/price-history?per_page=2&page=2'))->assertOk()->assertJsonPath('data.prices.0.product_name', 'Rice')
        ->assertJsonPath('data.prices.0.old_price', 4000)->assertJsonPath('data.prices.1.old_price', null);
    $this->context->run($this->alpha, function () {
        $entry = ProductPriceHistory::firstOrFail();
        expect(fn () => $entry->update(['new_price' => 1]))->toThrow(LogicException::class);
        expect(fn () => $entry->delete())->toThrow(LogicException::class);
    });
});

it('prints only current active packaging labels and exact server prices without cost disclosure', function () {
    $id = $this->postJson(priceTestUrl(), $this->input)->assertCreated()->json('data.id');
    $pack = $this->postJson(priceTestUrl('/'.$id.'/packagings'), ['name' => 'Half kilo', 'base_qty' => '0.500', 'selling_price' => 2300, 'barcode' => 'RICE-HALF'])->assertCreated()->json('data.id');
    $this->putJson(priceTestUrl('/'.$id.'/packagings/'.$pack), ['selling_price' => 2500])->assertOk();
    $this->getJson(priceTestUrl('/'.$id.'/shelf-labels'))->assertOk()->assertJsonCount(2, 'data.labels')->assertJsonPath('data.labels.1.selling_price', 2500)
        ->assertJsonPath('data.labels.1.base_qty', '0.5')->assertJsonPath('data.labels.1.packaging_version', 2)->assertJsonMissingPath('data.labels.0.buying_price');
});

it('isolates history and labels by tenant and denies cashiers and validates bounded pagination', function () {
    $id = $this->postJson(priceTestUrl(), $this->input)->assertCreated()->json('data.id');
    $this->getJson(priceTestUrl('/'.$id.'/price-history?per_page=101'))->assertUnprocessable();
    foreach (['price-history', 'shelf-labels'] as $path) {
        $this->withToken($this->alphaCashierToken)->getJson(priceTestUrl('/'.$id.'/'.$path))->assertForbidden();
        $this->withToken($this->betaOwnerToken)->getJson(priceTestUrl('/'.$id.'/'.$path, 'beta'))->assertNotFound();
        $this->withToken($this->alphaStockkeeperToken)->getJson(priceTestUrl('/'.$id.'/'.$path))->assertOk();
    }
    $this->context->run($this->beta, fn () => expect(ProductPriceHistory::count())->toBe(0));
});

it('previews exact base and pack imports and commits all stock once with frozen replay identity', function () {
    $id = $this->postJson(priceTestUrl(), $this->input)->assertCreated()->json('data.id');
    $this->postJson(priceTestUrl('/'.$id.'/packagings'), ['name' => 'Half kilo', 'base_qty' => '0.500', 'selling_price' => 2300, 'barcode' => 'RICE-HALF'])->assertCreated();
    $body = ['operation_id' => (string) \Illuminate\Support\Str::uuid(), 'rows' => [
        ['barcode' => 'RICE-1', 'batch_number' => 'BASE', 'qty' => '0.750', 'cost_price' => 2000, 'expiry_date' => null],
        ['barcode' => 'RICE-HALF', 'batch_number' => 'PACK', 'qty' => '3', 'cost_price' => 2000, 'expiry_date' => null],
    ]];
    $review = $this->postJson(priceTestUrl('/import-stock/preview'), $body)->assertOk()->assertJsonPath('data.rows.1.base_qty', '1.5')->json('data');
    $commit = [...$body, 'review_hash' => $review['review_hash']];
    $result = $this->postJson(priceTestUrl('/import-stock'), $commit)->assertCreated()->assertJsonPath('data.row_count', 2)->assertJsonPath('data.replayed', false)->json('data');
    $this->postJson(priceTestUrl('/import-stock'), $commit)->assertOk()->assertJsonPath('data.import_id', $result['import_id'])->assertJsonPath('data.replayed', true);
    $this->getJson(priceTestUrl('/'.$id))->assertOk()->assertJsonPath('data.stock', '2.25');
    $this->postJson(priceTestUrl('/import-stock'), [...$commit, 'rows' => [$body['rows'][0]]])->assertStatus(409)->assertJsonPath('error.code', 'OPERATION_ID_REUSED');
    $this->withToken($this->alphaStockkeeperToken)->postJson(priceTestUrl('/import-stock'), $commit)->assertStatus(409);
    $this->context->run($this->alpha, function () {
        expect(\App\Models\ProductBatch::count())->toBe(2); expect(\App\Models\StockMovement::count())->toBe(2);
        $journal = \App\Models\CatalogueImportOperation::firstOrFail(); expect(fn () => $journal->delete())->toThrow(LogicException::class);
    });
});

it('rejects invalid import rows, changed reviews and rolls back every stock receipt on a later-row failure', function () {
    $id = $this->postJson(priceTestUrl(), $this->input)->assertCreated()->json('data.id');
    $this->postJson(priceTestUrl(), [...$this->input, 'name' => 'Other rice', 'barcode' => 'OTHER'])->assertCreated();
    $row = ['barcode' => 'RICE-1', 'batch_number' => 'BASE', 'qty' => '0.750', 'cost_price' => 2000, 'expiry_date' => null];
    $body = ['operation_id' => (string) \Illuminate\Support\Str::uuid(), 'rows' => [$row]];
    foreach ([[$row, $row], [[...$row, 'barcode' => 'UNKNOWN']], [[...$row, 'qty' => '0.0001']], [[...$row, 'expiry_date' => '2000-01-01']]] as $rows) {
        $this->postJson(priceTestUrl('/import-stock/preview'), [...$body, 'rows' => $rows])->assertUnprocessable()->assertJsonPath('error.code', 'IMPORT_ROW_INVALID');
    }
    $review = $this->postJson(priceTestUrl('/import-stock/preview'), $body)->assertOk()->json('data.review_hash');
    $this->putJson(priceTestUrl('/'.$id), ['selling_price' => 5000])->assertOk();
    $this->postJson(priceTestUrl('/import-stock'), [...$body, 'review_hash' => $review])->assertStatus(409)->assertJsonPath('error.code', 'IMPORT_REVIEW_CHANGED');
    $body['rows'][] = [...$row, 'barcode' => 'OTHER', 'batch_number' => 'FAIL'];
    $review = $this->postJson(priceTestUrl('/import-stock/preview'), $body)->assertOk()->json('data.review_hash');
    $stock = new class($this->context) extends \App\Services\StockService {
        public function receive(User $actor, Product $product, array $input): array {
            if ($input['batch_number'] === 'FAIL') { throw new \App\Exceptions\ApiException('INJECTED_FAILURE', 'Injected stock receipt failure.', 409); }
            return parent::receive($actor, $product, $input);
        }
    };
    app()->instance(\App\Services\StockService::class, $stock);
    app('router')->getRoutes()->match(\Illuminate\Http\Request::create(priceTestUrl('/import-stock'), 'POST'))->flushController();
    $this->postJson(priceTestUrl('/import-stock'), [...$body, 'review_hash' => $review])->assertStatus(409);
    $this->context->run($this->alpha, function () { expect(\App\Models\ProductBatch::count())->toBe(0); expect(\App\Models\StockMovement::count())->toBe(0); expect(\App\Models\CatalogueImportOperation::count())->toBe(0); });
});

it('exports server catalogue values with formula protection and tenant isolation', function () {
    $this->postJson(priceTestUrl(), [...$this->input, 'name' => '=SUM(A1)'])->assertCreated();
    $response = $this->get(priceTestUrl('/export'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $csv = $response->streamedContent(); expect($csv)->toContain("'=SUM(A1)")->toContain('RICE-1')->toContain('cost_price')->toContain('4000');
    $this->withToken($this->betaOwnerToken); expect($this->get(priceTestUrl('/export', 'beta'))->assertOk()->streamedContent())->not->toContain('RICE-1');
    $this->withToken($this->alphaCashierToken)->get(priceTestUrl('/export'))->assertForbidden();
    $this->withToken($this->alphaCashierToken)->postJson(priceTestUrl('/import-stock/preview'), ['operation_id' => (string) \Illuminate\Support\Str::uuid(), 'rows' => []])->assertForbidden();
});
