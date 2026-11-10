<?php

use App\Models\{Business, Branch, Device, Plan, User, Supplier, SupplierInvoice, SupplierPayment, SupplierReturn, SupplierCredit, SupplierAllocation};
use App\Services\StaffRoleService;
use App\Tenancy\TenantContext;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $this->travelTo(now()->setDate(2026, 10, 18)->setTime(8, 0));
    $plan = Plan::create(['code' => 'supplier-statement', 'name' => 'Statement', 'price_monthly' => 0, 'limits' => [], 'features' => []]);
    foreach (['alpha', 'beta'] as $shop) {
        $business = Business::create(['name' => $shop, 'subdomain' => $shop, 'plan_id' => $plan->id, 'owner_phone' => '0700000001']);
        $this->{$shop} = $business;
        $this->context->run($business, function () use ($shop) {
            app(StaffRoleService::class)->provision();
            $branch = Branch::create(['name' => 'Main']);
            $device = Device::create(['device_uid' => 'supplier-statement', 'name' => 'Test device', 'status' => 'approved']);
            foreach (['owner', 'manager', 'stockkeeper', 'cashier'] as $index => $role) {
                $actor = User::create(['name' => $role, 'phone' => '071000000'.$index, 'pin_hash' => '1234', 'must_change_pin' => false, 'branch_id' => $branch->id, ...($role === 'owner' ? ['email' => $shop.'@example.test', 'password_hash' => 'statement-password-12'] : [])]);
                app(StaffRoleService::class)->assign($actor, $role);
                $token = $actor->createToken('statement', ['*'], now()->addDay());
                $token->accessToken->device_id = $device->id; $token->accessToken->save();
                $this->{$shop.ucfirst($role)} = $actor;
                $this->{$shop.ucfirst($role).'Token'} = $token->plainTextToken;
            }
            $supplier = Supplier::create(['name' => '=PRIVATE supplier', 'is_active' => true, 'revision' => 1]);
            $this->{$shop.'Supplier'} = $supplier;
            $invoice = SupplierInvoice::create(['supplier_id' => $supplier->id, 'actor_id' => $this->{$shop.'Owner'}->id, 'invoice_reference' => '=CMD()', 'invoice_hash' => hash('sha256', 'invoice'), 'original_amount' => 1000, 'remaining_amount' => 600, 'lines' => [], 'invoice_date' => '2026-10-10', 'created_at' => '2026-10-10 20:59:59']);
            $payment = SupplierPayment::create(['supplier_id' => $supplier->id, 'actor_id' => $this->{$shop.'Owner'}->id, 'source' => 'bank', 'amount' => 200, 'reference' => 'BANK-1', 'reference_hash' => hash('sha256', 'payment'), 'paid_on' => '2026-10-10', 'created_at' => '2026-10-10 21:00:00']);
            $returned = SupplierReturn::create(['supplier_id' => $supplier->id, 'invoice_id' => $invoice->id, 'actor_id' => $this->{$shop.'Owner'}->id, 'lines' => [], 'reason' => 'Returned', 'expected_credit' => 300, 'created_at' => '2026-10-10 21:00:00']);
            $credit = SupplierCredit::create(['supplier_id' => $supplier->id, 'return_id' => $returned->id, 'actor_id' => $this->{$shop.'Owner'}->id, 'reference' => 'CREDIT-1', 'reference_hash' => hash('sha256', 'credit'), 'amount' => 300, 'remaining_amount' => 100, 'created_at' => '2026-10-10 21:00:00']);
            SupplierAllocation::create(['supplier_id' => $supplier->id, 'invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'amount' => 200]);
            SupplierAllocation::create(['supplier_id' => $supplier->id, 'invoice_id' => $invoice->id, 'credit_id' => $credit->id, 'amount' => 200]);
        });
    }
    $this->withToken($this->alphaOwnerToken);
});

afterEach(function () { $this->context->clear(); $this->travelBack(); });

function supplierStatementEndpoint($test, string $suffix = ''): string { return 'http://alpha.bizismart.test/api/v1/suppliers/'.$test->alphaSupplier->publicId().'/statement'.$suffix; }

it('uses Tanzania boundaries and exact opening balances even on a later page', function () {
    $url = supplierStatementEndpoint($this).'?from=2026-10-11&to=2026-10-11&per_page=1&page=2';
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.opening_balance', 1000)
        ->assertJsonPath('data.charges', 0)
        ->assertJsonPath('data.payments', 200)
        ->assertJsonPath('data.credits', 300)
        ->assertJsonPath('data.closing_balance', 500)
        ->assertJsonPath('data.outstanding_payable', 600)
        ->assertJsonPath('data.unapplied_credit', 100)
        ->assertJsonPath('data.meta.total', 2)
        ->assertJsonPath('data.entries.0.kind', 'payment')
        ->assertJsonPath('data.entries.0.balance', 500);
});

it('does not post returns or credit allocations a second time and preserves empty-period balances', function () {
    $this->getJson(supplierStatementEndpoint($this).'?from=2026-10-10&to=2026-10-11')->assertOk()
        ->assertJsonCount(3, 'data.entries')->assertJsonPath('data.closing_balance', 500);
    $this->getJson(supplierStatementEndpoint($this).'?from=2026-10-12&to=2026-10-12')->assertOk()
        ->assertJsonCount(0, 'data.entries')->assertJsonPath('data.opening_balance', 500)->assertJsonPath('data.closing_balance', 500);
});

it('exports the entire period and escapes spreadsheet formulas', function () {
    $response = $this->get(supplierStatementEndpoint($this, '.csv').'?from=2026-10-10&to=2026-10-11&per_page=1&page=2')->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain("'=CMD()", "'=PRIVATE supplier", 'BANK-1', 'CREDIT-1');
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
});

it('rejects invalid periods and keeps supplier financial history owner-only and tenant-scoped', function () {
    $this->getJson(supplierStatementEndpoint($this).'?from=2026-01-01&to=2027-01-02')->assertUnprocessable();
    $this->getJson(supplierStatementEndpoint($this).'?from=2026-10-12&to=2026-10-11')->assertUnprocessable();
    $this->getJson('http://alpha.bizismart.test/api/v1/suppliers/'.$this->betaSupplier->publicId().'/statement')->assertNotFound();
    foreach (['Manager', 'Stockkeeper', 'Cashier'] as $role) {
        $this->withToken($this->{'alpha'.$role.'Token'})->getJson(supplierStatementEndpoint($this))->assertForbidden();
        $this->getJson(supplierStatementEndpoint($this, '.csv'))->assertForbidden();
    }
});
