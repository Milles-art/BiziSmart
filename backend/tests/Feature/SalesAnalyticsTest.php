<?php

use App\Models\{Business, Branch, Customer, Device, Plan, Product, User};
use App\Services\{StaffRoleService, StockService, TillService};
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $this->travelTo(now()->setDate(2026, 10, 13)->setTime(9, 0));
    $plan = Plan::create(['code' => 'analytics', 'name' => 'Analytics', 'price_monthly' => 0, 'limits' => ['tills' => 5], 'features' => []]);
    foreach (['alpha', 'beta'] as $label) {
        $shop = Business::create(['name' => $label, 'subdomain' => $label, 'plan_id' => $plan->id, 'owner_phone' => '0700000001']);
        $this->{$label} = $shop;
        $this->context->run($shop, function () use ($label) {
            app(StaffRoleService::class)->provision();
            $branch = Branch::create(['name' => 'Main']);
            $this->{$label.'Branch'} = $branch;
            $device = Device::create(['device_uid' => 'analytics-device', 'name' => 'Analytics device', 'status' => 'approved']);
            foreach (['owner' => '0710000001', 'manager' => '0710000002', 'cashier' => '0710000003', 'stockkeeper' => '0710000004'] as $role => $phone) {
                $attrs = ['branch_id' => $branch->id, 'name' => 'Private '.$role, 'phone' => $phone, 'pin_hash' => '1234', 'must_change_pin' => false];
                if ($role === 'owner') { $attrs['email'] = $label.'-owner@example.test'; $attrs['password_hash'] = 'a private password'; }
                $user = User::create($attrs);
                app(StaffRoleService::class)->assign($user, $role);
                $token = $user->createToken('analytics', ['*'], now()->addYear());
                $token->accessToken->device_id = $device->id; $token->accessToken->save();
                $user->withAccessToken($token->accessToken);
                $this->{$label.ucfirst($role)} = $user;
                $this->{$label.ucfirst($role).'Token'} = $token->plainTextToken;
            }
            foreach ([['Sold water', 'bottle', 8500], ['Idle water', 'bottle', 900]] as [$name, $unit, $price]) {
                $product = Product::create(['name' => $name, 'quantity_type' => 'counted', 'unit' => $unit, 'selling_price' => $price, 'buying_price' => 100, 'reorder_level_atoms' => 0, 'is_active' => true]);
                $this->{$label.ucfirst(str_replace(' ', '', $name))} = $product;
                app(StockService::class)->receive($this->{$label.'Stockkeeper'}, $product, ['operation_id' => (string) Str::uuid(), 'batch_number' => 'A-'.$name, 'qty' => '20', 'cost_price' => 100, 'expiry_date' => null]);
            }
            $this->{$label.'Till'} = app(TillService::class)->open($this->{$label.'Cashier'}, ['operation_id' => (string) Str::uuid(), 'branch_id' => $branch->publicId(), 'opening_float' => 20000])['till'];
        });
        analyticsSale($this, $label, $this->{$label.'Soldwater'}->publicId(), '3');
    }
    $this->withToken($this->alphaOwnerToken);
});

afterEach(function () { $this->context->clear(); $this->travelBack(); });

function analyticsUrl(string $path, string $shop = 'alpha'): string { return 'http://'.$shop.'.bizismart.test/api/v1/'.$path; }

function analyticsSale($test, string $shop, string $productId, string $qty, ?string $customerId = null, int $credit = 0): array
{
    $items = [['product_id' => $productId, 'qty' => $qty, 'packaging_id' => null]];
    $body = ['branch_id' => $test->{$shop.'Branch'}->publicId(), 'till_id' => $test->{$shop.'Till'}['id'], 'items' => $items];
    if ($customerId) { $body['customer_id'] = $customerId; $body['credit_amount'] = $credit; }
    $test->withToken($test->{$shop.'CashierToken'});
    $review = $test->postJson(analyticsUrl('sales/preview', $shop), $body)->assertOk()->json('data');
    $response = $test->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson(analyticsUrl('sales', $shop), [...$body, 'review_hash' => $review['review_hash'], 'cash_tendered' => max(20000, $review['total'] + 5000)])
        ->assertCreated()->json('data');
    $test->withToken($test->{$shop.'OwnerToken'});

    return $response;
}

it('splits exact payment methods, ranks sellers and lists stock that recorded nothing', function () {
    $data = $this->getJson(analyticsUrl('reports/sales-analytics?from=2026-10-13&to=2026-10-13'))->assertOk()->json('data');

    $methods = collect($data['payment_methods'])->keyBy('method');
    expect($methods['cash']['sales'])->toBe(25500)->and($methods['cash']['refunds'])->toBe(0)->and($methods['cash']['net'])->toBe(25500);
    expect($methods['mpesa']['sales'])->toBe(0)->and($methods['credit']['sales'])->toBe(0);

    expect($data['hourly'])->toHaveCount(1)
        ->and($data['hourly'][0]['hour'])->toBe(12)
        ->and($data['hourly'][0]['sales'])->toBe(25500)
        ->and($data['hourly'][0]['net'])->toBe(25500);

    expect($data['top_sellers'])->toHaveCount(1)
        ->and($data['top_sellers'][0]['name'])->toBe('Sold water')
        ->and($data['top_sellers'][0]['qty'])->toBe('3')
        ->and($data['top_sellers'][0]['sales_count'])->toBe(1)
        ->and($data['top_sellers'][0]['revenue'])->toBe(25500)
        ->and($data['top_sellers'][0]['cost'])->toBe(300)
        ->and($data['top_sellers'][0]['vat_amount'])->toBe(3890)
        ->and($data['top_sellers'][0]['net_revenue'])->toBe(21610)
        ->and($data['top_sellers'][0]['profit'])->toBe(21310)
        ->and($data['top_sellers'][0]['product_id'])->toStartWith('prod_');

    expect($data['slow_movers'])->toHaveCount(1)
        ->and($data['slow_movers'][0]['name'])->toBe('Idle water')
        ->and($data['slow_movers'][0]['stock_qty'])->toBe('20')
        ->and($data['slow_movers'][0]['stock_value'])->toBe(2000);

    expect($data['period']['timezone'])->toBe('Africa/Dar_es_Salaam')
        ->and($data['basis'])->toBe('event_date_accrual');
});

it('separates cash, mobile money and customer credit without double counting', function () {
    $this->context->run($this->alpha, function () {
        Customer::create(['name' => 'Asha', 'credit_limit' => 50000]);
    });
    $customer = $this->context->run($this->alpha, fn () => Customer::first()->publicId());

    analyticsSale($this, 'alpha', $this->alphaSoldwater->publicId(), '1', $customer, 8500);

    $data = $this->getJson(analyticsUrl('reports/sales-analytics?from=2026-10-13&to=2026-10-13'))->assertOk()->json('data');
    $methods = collect($data['payment_methods'])->keyBy('method');
    expect($methods['cash']['sales'])->toBe(25500)
        ->and($methods['credit']['sales'])->toBe(8500)
        ->and($methods['mpesa']['sales'])->toBe(0);
    // One sale paid with both cash and credit must not be counted twice.
    expect(collect($data['payment_methods'])->sum('sales'))->toBe(34000);
    expect($data['top_sellers'][0]['qty'])->toBe('4')->and($data['top_sellers'][0]['revenue'])->toBe(34000);
});

it('reverses a refunded line inside the ranking and the payment split', function () {
    $sale = analyticsSale($this, 'alpha', $this->alphaSoldwater->publicId(), '2');
    // Refunds settle from the acting user's own till, so the owner opens one.
    $ownerTill = $this->context->run($this->alpha, fn () => app(TillService::class)->open($this->alphaOwner, ['operation_id' => (string) Str::uuid(), 'branch_id' => $this->alphaBranch->publicId(), 'opening_float' => 50000])['till']);
    $body = ['operation_id' => (string) Str::uuid(), 'sale_id' => $sale['id'], 'till_id' => $ownerTill['id'],
        'reason' => 'Customer return', 'items' => [['line_index' => 0, 'qty' => '1', 'disposition' => 'sellable']]];
    $this->withToken($this->alphaOwnerToken);
    $review = $this->postJson(analyticsUrl('returns/preview'), $body)->assertOk()->json('data');
    $approval = $this->postJson('http://alpha.bizismart.test/api/v1/auth/supervisor-check', [
        'phone' => '0710000002', 'pin' => '1234', 'permission' => 'refund',
        'operation_id' => $body['operation_id'], 'payload_hash' => $review['review_hash'],
    ])->assertOk()->json('data');
    $this->withHeader('Idempotency-Key', $body['operation_id'])
        ->postJson(analyticsUrl('returns'), [...$body, 'review_hash' => $review['review_hash'], 'approval_token' => $approval['approval_token']])
        ->assertCreated();

    $data = $this->getJson(analyticsUrl('reports/sales-analytics?from=2026-10-13&to=2026-10-13'))->assertOk()->json('data');
    $methods = collect($data['payment_methods'])->keyBy('method');
    expect($methods['cash']['sales'])->toBe(42500)->and($methods['cash']['refunds'])->toBe(8500)->and($methods['cash']['net'])->toBe(34000);
    expect($data['hourly'][0]['refunds'])->toBe(8500)->and($data['hourly'][0]['net'])->toBe(34000);
    expect($data['top_sellers'][0]['qty'])->toBe('4')->and($data['top_sellers'][0]['revenue'])->toBe(34000);

    $this->context->run($this->alpha, fn () => $this->alphaSoldwater->delete());
    $archived = $this->getJson(analyticsUrl('reports/sales-analytics?from=2026-10-13&to=2026-10-13'))->assertOk()->json('data');
    expect($archived['top_sellers'][0])->toMatchArray(['name' => 'Sold water', 'qty' => '4', 'revenue' => 34000]);
    expect($archived['payment_methods'])->toBe($data['payment_methods']);
});

it('ignores sales outside the chosen window and returns an empty window honestly', function () {
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(14, 0));
    analyticsSale($this, 'alpha', $this->alphaIdlewater->publicId(), '2');

    $later = $this->getJson(analyticsUrl('reports/sales-analytics?from=2026-10-20&to=2026-10-20'))->assertOk()->json('data');
    expect($later['top_sellers'][0]['name'])->toBe('Idle water')->and($later['slow_movers'][0]['name'])->toBe('Sold water');

    $empty = $this->getJson(analyticsUrl('reports/sales-analytics?from=2026-10-01&to=2026-10-05'))->assertOk()->json('data');
    expect($empty['top_sellers'])->toBe([])->and($empty['hourly'])->toBe([])
        ->and(collect($empty['payment_methods'])->sum('sales'))->toBe(0);
});

it('keeps the analytics report owner-only, tenant-scoped and date-validated', function () {
    $this->withToken($this->alphaCashierToken)->getJson(analyticsUrl('reports/sales-analytics?from=2026-10-13&to=2026-10-13'))->assertForbidden();

    $beta = $this->withToken($this->betaOwnerToken)->getJson(analyticsUrl('reports/sales-analytics?from=2026-10-13&to=2026-10-13', 'beta'))->assertOk()->json('data');
    expect($beta['top_sellers'][0]['name'])->toBe('Sold water');
    expect(collect($beta['payment_methods'])->sum('sales'))->toBe(25500);

    // A token from one shop can never read another shop's analytics.
    $this->withToken($this->betaOwnerToken)->getJson(analyticsUrl('reports/sales-analytics?from=2026-10-13&to=2026-10-13', 'alpha'))->assertUnauthorized();

    $this->withToken($this->alphaOwnerToken);
    $this->getJson(analyticsUrl('reports/sales-analytics?from=2026-10-13&to=2026-10-01'))->assertStatus(422);
    $this->getJson(analyticsUrl('reports/sales-analytics?from=13-10-2026&to=2026-10-13'))->assertStatus(422);
});