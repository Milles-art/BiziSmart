<?php

use App\Models\{Business, Branch, Device, Plan, Product, User};
use App\Services\{StaffRoleService, StockService, TillService};
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $this->travelTo(now()->setDate(2026, 10, 13)->setTime(9, 0));
    $plan = Plan::create(['code' => 'report-endpoints', 'name' => 'Report endpoints', 'price_monthly' => 0, 'limits' => ['tills' => 5], 'features' => []]);
    foreach (['alpha', 'beta'] as $label) {
        $shop = Business::create(['name' => $label, 'subdomain' => $label, 'plan_id' => $plan->id, 'owner_phone' => '0700000001']);
        $this->{$label} = $shop;
        $this->context->run($shop, function () use ($label) {
            app(StaffRoleService::class)->provision();
            $branch = Branch::create(['name' => 'Main']);
            $this->{$label.'Branch'} = $branch;
            $device = Device::create(['device_uid' => 'report-endpoints-device', 'name' => 'Report device', 'status' => 'approved']);
            foreach (['owner' => '0710000001', 'manager' => '0710000002', 'cashier' => '0710000003', 'stockkeeper' => '0710000004'] as $role => $phone) {
                $attrs = ['branch_id' => $branch->id, 'name' => 'Private '.$role, 'phone' => $phone, 'pin_hash' => '1234', 'must_change_pin' => false];
                if ($role === 'owner') { $attrs['email'] = $label.'-owner@example.test'; $attrs['password_hash'] = 'a private password'; }
                $user = User::create($attrs);
                app(StaffRoleService::class)->assign($user, $role);
                $token = $user->createToken('report-endpoints', ['*'], now()->addDay());
                $token->accessToken->device_id = $device->id; $token->accessToken->save();
                $user->withAccessToken($token->accessToken);
                $this->{$label.ucfirst($role)} = $user;
                $this->{$label.ucfirst($role).'Token'} = $token->plainTextToken;
            }
            $product = Product::create(['name' => 'Test water', 'quantity_type' => 'counted', 'unit' => 'bottle', 'selling_price' => 8500, 'buying_price' => 100, 'reorder_level_atoms' => 0, 'is_active' => true]);
            $this->{$label.'Product'} = $product;
            $this->{$label.'Till'} = app(TillService::class)->open($this->{$label.'Cashier'}, ['operation_id' => (string) Str::uuid(), 'branch_id' => $branch->publicId(), 'opening_float' => 20000])['till'];
            app(StockService::class)->receive($this->{$label.'Stockkeeper'}, $product, ['operation_id' => (string) Str::uuid(), 'batch_number' => 'UNDATED', 'qty' => '20', 'cost_price' => 100, 'expiry_date' => null]);
        });
        $sale = reportsTestSale($this, $label);
        $this->{$label.'SaleId'} = $sale['id'];
        $this->{$label.'SaleReceipt'} = $sale['receipt_number'];
    }
    $this->withToken($this->alphaOwnerToken);
});

afterEach(function () { $this->context->clear(); $this->travelBack(); });

function reportsTestUrl(string $path, string $shop = 'alpha'): string { return 'http://'.$shop.'.bizismart.test/api/v1/'.$path; }

function reportsTestSale($test, string $shop): array
{
    $body = ['branch_id' => $test->{$shop.'Branch'}->publicId(), 'till_id' => $test->{$shop.'Till'}['id'],
        'items' => [['product_id' => $test->{$shop.'Product'}->publicId(), 'qty' => '1', 'packaging_id' => null]]];
    $test->withToken($test->{$shop.'CashierToken'});
    $review = $test->postJson(reportsTestUrl('sales/preview', $shop), $body)->assertOk()->json('data');
    return $test->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson(reportsTestUrl('sales', $shop), [...$body, 'review_hash' => $review['review_hash'], 'cash_tendered' => max(10000, $review['total'])])
        ->assertCreated()->json('data');
}

it('reports exact own-sale rows with integer VAT and net revenue while isolating tenant rows', function () {
    $data = $this->withToken($this->alphaOwnerToken)
        ->getJson(reportsTestUrl('reports/sales?from=2026-10-13&to=2026-10-13'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(1, 'data.sales')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.per_page', 50)
        ->assertJsonMissingPath('data.sales.0.business_id')
        ->json('data');
    $row = $data['sales'][0];
    expect($row['id'])->toBe($this->alphaSaleId)
        ->and($row['receipt_number'])->toBe($this->alphaSaleReceipt)
        ->and($row['receipt_number'])->toMatch('/^RCP-2026-\d{6}$/')
        ->and($row['total'])->toBe(8500)
        ->and($row['vat_amount'])->toBe(1297)
        ->and($row['net_revenue'])->toBe(8500 - 1297)
        ->and($row['branch'])->toBe('Main')
        ->and($row['cashier'])->toBe($this->alphaCashier->name);

    $betaRow = $this->withToken($this->betaOwnerToken)
        ->getJson(reportsTestUrl('reports/sales?from=2026-10-13&to=2026-10-13', 'beta'))
        ->assertOk()
        ->assertJsonCount(1, 'data.sales')
        ->json('data.sales.0');
    expect($betaRow['id'])->toBe($this->betaSaleId)
        ->and($betaRow['id'])->not->toBe($this->alphaSaleId)
        ->and($betaRow['receipt_number'])->toBe($this->betaSaleReceipt);

    $this->withToken($this->alphaOwnerToken)
        ->getJson(reportsTestUrl('reports/sales?from=2026-10-14&to=2026-10-14'))
        ->assertOk()->assertJsonCount(0, 'data.sales')->assertJsonPath('meta.total', 0);
});

it('requires owner report authority and validates periods pagination and tenant context', function () {
    $sales = fn (string $query = 'from=2026-10-13&to=2026-10-13') => reportsTestUrl('reports/sales?'.$query);
    $this->withToken($this->alphaCashierToken)->getJson($sales())->assertForbidden();
    $this->withToken($this->alphaManagerToken)->getJson($sales())->assertForbidden();
    $this->withToken($this->alphaCashierToken)->getJson(reportsTestUrl('reports/stock?from=2026-10-13&to=2026-10-13'))->assertForbidden();
    $this->withToken($this->alphaOwnerToken)->getJson(reportsTestUrl('reports/stock'))->assertUnprocessable();
    $this->withToken($this->alphaOwnerToken)->getJson($sales('from=2026-10-14&to=2026-10-13'))->assertUnprocessable();
    $this->withToken($this->alphaOwnerToken)->getJson($sales('from=2026-10-13&to=2027-10-14'))->assertUnprocessable();
    $this->withToken($this->alphaOwnerToken)->getJson($sales('from=2026-10-13&to=2026-10-13&per_page=101'))->assertUnprocessable();
    $this->withToken($this->alphaOwnerToken)->getJson($sales('from=2026-10-13&to=2026-10-13&branch_id='.$this->betaBranch->publicId()))->assertNotFound();
    $this->withToken($this->betaOwnerToken)->getJson($sales())->assertUnauthorized();
});

it('lists exact stock quantities values and statuses isolated per tenant', function () {
    $stock = $this->withToken($this->alphaOwnerToken)
        ->getJson(reportsTestUrl('reports/stock?from=2026-10-13&to=2026-10-13'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonStructure(['data' => ['stock', 'as_of'], 'meta' => ['page', 'per_page', 'total']])
        ->json('data');
    $alpha = collect($stock['stock'])->firstWhere('product_name', 'Test water');
    expect($alpha)->not->toBeNull()
        ->and($alpha['qty'])->toBe('19')
        ->and($alpha['unit'])->toBe('bottle')
        ->and($alpha['batch_number'])->toBe('UNDATED')
        ->and($alpha['value'])->toBe(1900)
        ->and($alpha['status'])->toBe('sellable')
        ->and($alpha['expiry_date'])->toBeNull()
        ->and(is_int($alpha['cost_price']))->toBeTrue();

    $betaIds = $this->withToken($this->betaOwnerToken)
        ->getJson(reportsTestUrl('reports/stock?from=2026-10-13&to=2026-10-13', 'beta'))
        ->assertOk()->assertJsonCount(1, 'data.stock')
        ->json('data.stock.*.id');
    expect($betaIds)->not->toContain($alpha['id'])
        ->and($stock['as_of'])->not->toBeNull();
});
