<?php

use App\Models\{Business, Branch, Customer, Device, Plan, SaleDebt, User};
use App\Services\{DebtService, StaffRoleService, StockService, TillService};
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $this->travelTo(now()->setDate(2026, 10, 13)->setTime(9, 0));
    $plan = Plan::create(['code' => 'statement', 'name' => 'Statement', 'price_monthly' => 0, 'limits' => ['tills' => 5], 'features' => []]);
    foreach (['alpha', 'beta'] as $label) {
        $shop = Business::create(['name' => $label, 'subdomain' => $label, 'plan_id' => $plan->id, 'owner_phone' => '0700000001']);
        $this->{$label} = $shop;
        $this->context->run($shop, function () use ($label) {
            app(StaffRoleService::class)->provision();
            $branch = Branch::create(['name' => 'Main']);
            $this->{$label.'Branch'} = $branch;
            $device = Device::create(['device_uid' => 'statement-device', 'name' => 'Statement device', 'status' => 'approved']);
            foreach (['owner' => '0710000001', 'manager' => '0710000002', 'cashier' => '0710000003', 'stockkeeper' => '0710000004'] as $role => $phone) {
                $attrs = ['branch_id' => $branch->id, 'name' => 'Private '.$role, 'phone' => $phone, 'pin_hash' => '1234', 'must_change_pin' => false];
                if ($role === 'owner') { $attrs['email'] = $label.'-owner@example.test'; $attrs['password_hash'] = 'a private password'; }
                $user = User::create($attrs);
                app(StaffRoleService::class)->assign($user, $role);
                $token = $user->createToken('statement', ['*'], now()->addYear());
                $token->accessToken->device_id = $device->id; $token->accessToken->save();
                $user->withAccessToken($token->accessToken);
                $this->{$label.ucfirst($role)} = $user;
                $this->{$label.ucfirst($role).'Token'} = $token->plainTextToken;
            }
            $this->{$label.'Customer'} = Customer::create(['name' => 'Asha', 'phone' => '0711000001', 'credit_limit' => 200000]);
            $this->{$label.'OtherCustomer'} = Customer::create(['name' => 'Neema', 'phone' => '0711000002', 'credit_limit' => 200000]);
            $this->{$label.'Product'} = \App\Models\Product::create(['name' => 'Rice', 'quantity_type' => 'counted', 'unit' => 'kg', 'selling_price' => 20000, 'buying_price' => 12000, 'reorder_level_atoms' => 0, 'is_active' => true]);
            app(StockService::class)->receive($this->{$label.'Stockkeeper'}, $this->{$label.'Product'}, ['operation_id' => (string) Str::uuid(), 'batch_number' => 'A-1', 'qty' => '50', 'cost_price' => 12000, 'expiry_date' => null]);
            $this->{$label.'Till'} = app(TillService::class)->open($this->{$label.'Cashier'}, ['operation_id' => (string) Str::uuid(), 'branch_id' => $branch->publicId(), 'opening_float' => 100000])['till'];
        });
    }
    $this->withToken($this->alphaOwnerToken);
});

afterEach(function () { $this->context->clear(); $this->travelBack(); });

it('paginates statement movements without truncating the full export or its balances', function () {
    $id = $this->alphaCustomer->publicId();
    creditSale($this, 'alpha', $id, '1');
    $last = creditSale($this, 'alpha', $id, '2');
    $path = 'customers/'.$id.'/statement';
    $page = $this->getJson(statementUrl($path.'?per_page=1&page=2'))->assertOk()->json('data');
    expect($page['entries'])->toHaveCount(1)
        ->and($page['entries'][0]['label'])->toBe($last['receipt_number'])
        ->and($page['entries'][0]['balance'])->toBe(3000)
        ->and($page['meta'])->toMatchArray(['total' => 2, 'page' => 2, 'per_page' => 1]);
    $full = $this->getJson(statementUrl($path.'/export?per_page=1&page=2'))->assertOk()->json('data');
    expect($full['entries'])->toHaveCount(2)
        ->and($full['charges'])->toBe(3000)->and($full['closing_balance'])->toBe(3000);
    $this->getJson(statementUrl('customers/'.$this->betaCustomer->publicId().'/statement/export'))->assertNotFound();
    $this->withToken($this->alphaStockkeeperToken)->getJson(statementUrl($path.'/export'))->assertForbidden();
});

function statementUrl(string $path, string $shop = 'alpha'): string { return 'http://'.$shop.'.bizismart.test/api/v1/'.$path; }

function creditSale($test, string $shop, string $customerId, string $qty, int $credit = 0): array
{
    $body = ['branch_id' => $test->{$shop.'Branch'}->publicId(), 'till_id' => $test->{$shop.'Till'}['id'],
        'items' => [['product_id' => $test->{$shop.'Product'}->publicId(), 'qty' => $qty, 'packaging_id' => null]],
        'customer_id' => $customerId, 'credit_amount' => $credit > 0 ? $credit : 1000 * (int) $qty];
    $test->withToken($test->{$shop.'CashierToken'});
    $review = $test->postJson(statementUrl('sales/preview', $shop), $body)->assertOk()->json('data');
    $sale = $test->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson(statementUrl('sales', $shop), [...$body, 'review_hash' => $review['review_hash'], 'cash_tendered' => $review['total']])
        ->assertCreated()->json('data');
    $test->withToken($test->{$shop.'OwnerToken'});

    return $sale;
}

it('lists credit sales, collections and returns chronologically with an exact running balance', function () {
    $customer = $this->alphaCustomer->publicId();
    $first = creditSale($this, 'alpha', $customer, '2');
    $this->travelTo(now()->addDay()->setTime(10, 30));
    $second = creditSale($this, 'alpha', $customer, '1');

    $statement = $this->getJson(statementUrl('customers/'.$customer.'/statement'))->assertOk()->json('data');
    expect($statement['customer']['id'])->toBe($customer)
        ->and($statement['customer']['name'])->toBe('Asha')
        ->and($statement['currency'])->toBe('TSh')
        ->and($statement['opening_balance'])->toBe(0)
        ->and($statement['charges'])->toBe(3000)
        ->and($statement['payments'])->toBe(0)
        ->and($statement['closing_balance'])->toBe(3000)
        ->and($statement['outstanding_balance'])->toBe(3000);

    expect($statement['entries'])->toHaveCount(2);
    expect($statement['entries'][0]['kind'])->toBe('credit_sale')
        ->and($statement['entries'][0]['charge'])->toBe(2000)
        ->and($statement['entries'][0]['balance'])->toBe(2000)
        ->and($statement['entries'][0]['label'])->toBe($first['receipt_number'])
        ->and($statement['entries'][0]['date'])->toBe('2026-10-13');
    expect($statement['entries'][1]['charge'])->toBe(1000)
        ->and($statement['entries'][1]['balance'])->toBe(3000)
        ->and($statement['entries'][1]['label'])->toBe($second['receipt_number'])
        ->and($statement['entries'][1]['date'])->toBe('2026-10-14');
});

it('reduces the balance by a debt-first return and then a collection', function () {
    $customer = $this->alphaCustomer->publicId();
    $sale = creditSale($this, 'alpha', $customer, '2', 40000);

    // A cash return on an unpaid invoice reduces debt before any cash is paid out.
    $this->travelTo(now()->addDay()->setTime(11, 0));
    $ownerTill = $this->context->run($this->alpha, fn () => app(TillService::class)->open($this->alphaOwner, ['operation_id' => (string) Str::uuid(), 'branch_id' => $this->alphaBranch->publicId(), 'opening_float' => 50000])['till']);
    $body = ['operation_id' => (string) Str::uuid(), 'sale_id' => $sale['id'], 'till_id' => $ownerTill['id'],
        'reason' => 'Customer return', 'items' => [['line_index' => 0, 'qty' => '1', 'disposition' => 'sellable']]];
    $review = $this->postJson(statementUrl('returns/preview'), $body)->assertOk()->json('data');
    $approval = $this->postJson('http://alpha.bizismart.test/api/v1/auth/supervisor-check', [
        'phone' => '0710000002', 'pin' => '1234', 'permission' => 'refund',
        'operation_id' => $body['operation_id'], 'payload_hash' => $review['review_hash'],
    ])->assertOk()->json('data');
    $this->withHeader('Idempotency-Key', $body['operation_id'])
        ->postJson(statementUrl('returns'), [...$body, 'review_hash' => $review['review_hash'], 'approval_token' => $approval['approval_token']])
        ->assertCreated();

    $afterReturn = $this->getJson(statementUrl('customers/'.$customer.'/statement'))->assertOk()->json('data');
    expect($afterReturn['entries'])->toHaveCount(2);
    expect($afterReturn['closing_balance'])->toBe(20000)->and($afterReturn['outstanding_balance'])->toBe(20000);
    expect($afterReturn['entries'][1]['kind'])->toBe('return')
        ->and($afterReturn['entries'][1]['charge'])->toBe(0)
        ->and($afterReturn['entries'][1]['payment'])->toBe(0)
        ->and($afterReturn['entries'][1]['debt_reduction'])->toBe(20000);

    $collectionKey = (string) Str::uuid();
    $preview = $this->withToken($this->alphaCashierToken)->postJson(statementUrl('customers/'.$customer.'/payments/preview'), [
        'till_id' => $this->alphaTill['id'],
        'method' => 'cash', 'amount' => 20000, 'allocations' => [['sale_id' => $sale['id'], 'amount' => 20000]],
    ])->assertOk()->json('data');
    $this->withToken($this->alphaCashierToken)
        ->withHeader('Idempotency-Key', $collectionKey)
        ->postJson(statementUrl('customers/'.$customer.'/payments'), [
            'idempotency_key' => $collectionKey, 'till_id' => $this->alphaTill['id'],
            'method' => 'cash', 'amount' => 20000, 'allocations' => [['sale_id' => $sale['id'], 'amount' => 20000]],
            'review_hash' => $preview['review_hash'],
        ])->assertCreated();

    $statement = $this->getJson(statementUrl('customers/'.$customer.'/statement'))->assertOk()->json('data');
    expect($statement['charges'])->toBe(40000)->and($statement['payments'])->toBe(20000)
        ->and($statement['closing_balance'])->toBe(0)->and($statement['outstanding_balance'])->toBe(0);
    expect($statement['entries'])->toHaveCount(3);
    $last = end($statement['entries']);
    expect($last['kind'])->toBe('collection')->and($last['payment'])->toBe(20000)->and($last['balance'])->toBe(0)
        ->and($last['debt_reduction'])->toBe(20000);
});

it('opens a windowed statement with the balance implied by earlier journals', function () {
    $customer = $this->alphaCustomer->publicId();
    creditSale($this, 'alpha', $customer, '4');
    $this->travelTo(now()->addDays(5)->setTime(8, 15));
    $later = creditSale($this, 'alpha', $customer, '1');

    $statement = $this->getJson(statementUrl('customers/'.$customer.'/statement?from=2026-10-18&to=2026-10-18'))->assertOk()->json('data');
    expect($statement['period'])->toMatchArray(['from' => '2026-10-18', 'to' => '2026-10-18', 'timezone' => 'Africa/Dar_es_Salaam']);
    expect($statement['entries'])->toHaveCount(1)
        ->and($statement['opening_balance'])->toBe(4000)
        ->and($statement['charges'])->toBe(1000)
        ->and($statement['closing_balance'])->toBe(5000)
        ->and($statement['entries'][0]['label'])->toBe($later['receipt_number'])
        ->and($statement['outstanding_balance'])->toBe(5000);
});

it('keeps every other customer out of the statement and stays permission-gated', function () {
    $mine = $this->alphaCustomer->publicId();
    $theirs = $this->alphaOtherCustomer->publicId();
    creditSale($this, 'alpha', $mine, '1');
    creditSale($this, 'alpha', $theirs, '2');

    expect($this->getJson(statementUrl('customers/'.$mine.'/statement'))->assertOk()->json('data.charges'))->toBe(1000);
    expect($this->getJson(statementUrl('customers/'.$theirs.'/statement'))->assertOk()->json('data.charges'))->toBe(2000);

    // Another shop's customer public id can never be read here.
    $this->getJson(statementUrl('customers/'.$this->betaCustomer->publicId().'/statement'))->assertNotFound();

    $this->withToken($this->alphaStockkeeperToken);
    $this->getJson(statementUrl('customers/'.$mine.'/statement'))->assertForbidden();

    $this->withToken($this->alphaOwnerToken);
    $this->getJson(statementUrl('customers/'.$mine.'/statement?from=2026-10-20&to=2026-10-01'))->assertStatus(422);
    $this->getJson(statementUrl('customers/'.$mine.'/statement?from=20-10-2026'))->assertStatus(422);
});

it('reports an empty statement honestly for a customer with no credit history', function () {
    $fresh = $this->context->run($this->alpha, fn () => Customer::create(['name' => 'Fresh Walk-in', 'phone' => '0711000009', 'credit_limit' => 0])->publicId());
    $statement = $this->getJson(statementUrl('customers/'.$fresh.'/statement'))->assertOk()->json('data');
    expect($statement['entries'])->toBe([])
        ->and($statement['opening_balance'])->toBe(0)
        ->and($statement['closing_balance'])->toBe(0)
        ->and($statement['outstanding_balance'])->toBe(0);
});
