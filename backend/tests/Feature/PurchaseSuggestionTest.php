<?php

use App\Models\{Business, Branch, Device, Plan, Product, ProductBatch, Supplier, SupplierInvoice, SupplierOrder, User};
use App\Services\{PurchaseSuggestionService, StaffRoleService};
use App\Tenancy\TenantContext;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $plan = Plan::create(['code' => 'purchase-assistance', 'name' => 'Purchasing', 'price_monthly' => 0, 'limits' => [], 'features' => []]);
    foreach (['alpha', 'beta'] as $name) {
        $shop = Business::create(['name' => $name, 'subdomain' => $name, 'plan_id' => $plan->id, 'owner_phone' => '0700000001']);
        $this->{$name} = $shop;
        $this->context->run($shop, function () use ($name) {
            app(StaffRoleService::class)->provision();
            $branch = Branch::create(['name' => 'Main']);
            $device = Device::create(['device_uid' => 'suggestion-device', 'name' => 'Purchasing', 'status' => 'approved']);
            foreach (['owner', 'stockkeeper'] as $i => $role) {
                $attrs = ['name' => $role, 'phone' => '071000000'.$i, 'pin_hash' => '1234', 'must_change_pin' => false, 'branch_id' => $branch->id];
                if ($role === 'owner') { $attrs['email'] = $name.'-owner@example.test'; $attrs['password_hash'] = 'a private password'; }
                $user = User::create($attrs);
                app(StaffRoleService::class)->assign($user, $role);
                $token = $user->createToken('purchasing', ['*'], now()->addDay());
                $token->accessToken->device_id = $device->id;
                $token->accessToken->save();
                $this->{$name.ucfirst($role)} = $user->withAccessToken($token->accessToken);
                $this->{$name.ucfirst($role).'Token'} = $token->plainTextToken;
            }
            $this->{$name.'Supplier'} = Supplier::create(['name' => 'Supplier '.$name, 'is_active' => true, 'revision' => 1]);
            $this->{$name.'Product'} = Product::create(['name' => 'Rice '.$name, 'quantity_type' => 'weight', 'unit' => 'kg', 'buying_price' => 999, 'selling_price' => 4000, 'reorder_level_atoms' => 2501, 'is_active' => true]);
            $line = ['product_id' => $this->{$name.'Product'}->publicId(), 'cost_price' => 1234, 'packaging_id' => 'pkg_historical'];
            SupplierInvoice::create(['supplier_id' => $this->{$name.'Supplier'}->id, 'actor_id' => $this->{$name.'Owner'}->id, 'invoice_reference' => 'OLD', 'invoice_hash' => hash('sha256', 'OLD'), 'original_amount' => 1234, 'remaining_amount' => 1234, 'lines' => [$line], 'invoice_date' => '2026-01-01']);
            SupplierInvoice::create(['supplier_id' => $this->{$name.'Supplier'}->id, 'actor_id' => $this->{$name.'Owner'}->id, 'invoice_reference' => 'NEW', 'invoice_hash' => hash('sha256', 'NEW'), 'original_amount' => 2345, 'remaining_amount' => 2345, 'lines' => [[...$line, 'cost_price' => 2345]], 'invoice_date' => '2026-02-01']);
        });
    }
});
afterEach(fn () => $this->context->clear());

it('uses exact eligible grams and latest supplier base cost without writing an order or stock', function () {
    $this->context->run($this->alpha, function () {
        foreach ([[750, false, null], [1000, true, null], [1000, false, '2000-01-01']] as [$atoms, $quarantined, $expiry]) {
            ProductBatch::create(['product_id' => $this->alphaProduct->id, 'batch_number' => 'READ', 'qty_remaining_atoms' => $atoms, 'cost_price' => 8000, 'received_at' => now(), 'is_quarantined' => $quarantined, 'is_recalled' => false, 'expiry_date' => $expiry, 'operation_id' => (string) \Illuminate\Support\Str::uuid(), 'request_fingerprint' => str_repeat('a', 64), 'receipt' => []]);
        }
        $result = app(PurchaseSuggestionService::class)->suggestions($this->alphaOwner, $this->alphaSupplier->publicId(), []);
        expect($result['lines'])->toHaveCount(1);
        expect($result['lines'][0])->toMatchArray(['stock' => '0.75', 'reorder_level' => '2.501', 'shortage_to_reorder' => '1.751', 'suggested_qty' => '1.751', 'packaging_id' => null]);
        expect($result['lines'][0]['historical_cost']['cost_price'])->toBe(2345);
        expect($result['lines'][0]['historical_cost']['invoice_date'])->toBe('2026-02-01');
        expect(ProductBatch::count())->toBe(3)->and(SupplierOrder::count())->toBe(0);
    });
});

it('keeps the equality boundary manual and excludes products without supplier delivery history', function () {
    $this->context->run($this->alpha, function () {
        $this->alphaProduct->update(['reorder_level_atoms' => 0]);
        Product::create(['name' => 'No history', 'quantity_type' => 'counted', 'unit' => 'item', 'buying_price' => 12, 'selling_price' => 20, 'reorder_level_atoms' => 10, 'is_active' => true]);
        $result = app(PurchaseSuggestionService::class)->suggestions($this->alphaOwner, $this->alphaSupplier->publicId(), ['per_page' => 1]);
        expect($result['meta']['total'])->toBe(1)->and($result['lines'][0]['suggested_qty'])->toBeNull();
        expect($result['lines'][0]['shortage_to_reorder'])->toBe('0');
        $this->alphaProduct->update(['is_active' => false]);
        expect(app(PurchaseSuggestionService::class)->suggestions($this->alphaOwner, $this->alphaSupplier->publicId(), [])['lines'])->toBe([]);
    });
});

it('requires an owner and rejects cross-tenant suppliers without leaking evidence', function () {
    $this->withToken($this->alphaStockkeeperToken)->getJson('http://alpha.bizismart.test/api/v1/suppliers/'.$this->alphaSupplier->publicId().'/purchase-suggestions')->assertForbidden();
    $this->withToken($this->alphaOwnerToken)->getJson('http://alpha.bizismart.test/api/v1/suppliers/'.$this->betaSupplier->publicId().'/purchase-suggestions')->assertNotFound();
    $this->withToken($this->alphaOwnerToken)->getJson('http://alpha.bizismart.test/api/v1/suppliers/'.$this->alphaSupplier->publicId().'/purchase-suggestions?per_page=101')->assertUnprocessable();
});
