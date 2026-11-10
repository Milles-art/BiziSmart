<?php

use App\Models\{Business, Branch, Device, Plan, User};
use App\Services\StaffRoleService;
use App\Tenancy\TenantContext;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $plan = Plan::create(['code' => 'business-settings', 'name' => 'Settings', 'price_monthly' => 0, 'limits' => [], 'features' => []]);
    foreach (['alpha', 'beta'] as $label) {
        $shop = Business::create(['name' => $label, 'subdomain' => $label, 'plan_id' => $plan->id, 'owner_phone' => '0712345678', 'settings' => ['unrelated' => true]]);
        $this->{$label} = $shop;
        $this->context->run($shop, function () use ($label) {
            app(StaffRoleService::class)->provision();
            $branch = Branch::create(['name' => 'Main']);
            $device = Device::create(['device_uid' => 'settings-device', 'name' => 'Settings', 'status' => 'approved']);
            foreach (['owner', 'cashier'] as $role) {
                $user = User::create(['name' => $role, 'phone' => $role === 'owner' ? '0712345678' : '0712345679', 'pin_hash' => '1234',
                    'branch_id' => $branch->id, 'must_change_pin' => false, ...($role === 'owner' ? ['email' => 'owner@example.test', 'password_hash' => 'a private password'] : [])]);
                app(StaffRoleService::class)->assign($user, $role);
                $token = $user->createToken('settings', ['*'], now()->addHour());
                $token->accessToken->device_id = $device->id; $token->accessToken->save();
                $this->{$label.ucfirst($role).'Token'} = $token->plainTextToken;
            }
        });
    }
    $this->profile = ['name' => 'Fresh Choice Supermarket', 'address' => 'Shop address', 'phone' => '0712345678',
        'tin' => 'provided TIN', 'vrn' => null, 'header' => 'Welcome', 'footer' => 'Thank you', 'paper_width' => '58'];
});

afterEach(function () { $this->context->clear(); });

it('saves only the current owner business profile and preserves unrelated settings and VAT', function () {
    $this->withToken($this->alphaOwnerToken)->putJson('https://alpha.bizismart.test/api/v1/settings/business', $this->profile)
        ->assertOk()->assertJsonPath('data.name', 'Fresh Choice Supermarket')->assertJsonPath('data.paper_width', '58')
        ->assertJsonPath('data.currency', 'TZS');
    expect($this->alpha->fresh()->settings['unrelated'])->toBeTrue()
        ->and($this->alpha->fresh()->vat_rate)->toBe(18)->and($this->beta->fresh()->name)->toBe('beta');
    $this->withToken($this->betaOwnerToken)->getJson('https://beta.bizismart.test/api/v1/settings/business')
        ->assertOk()->assertJsonPath('data.name', 'beta')->assertJsonPath('data.tin', null);
});

it('lets authenticated staff read print details but only owners update them', function () {
    $this->withToken($this->alphaCashierToken)->getJson('https://alpha.bizismart.test/api/v1/settings/business')->assertOk();
    $this->putJson('https://alpha.bizismart.test/api/v1/settings/business', $this->profile)->assertForbidden();
    $this->withToken($this->alphaOwnerToken)->putJson('https://beta.bizismart.test/api/v1/settings/business', $this->profile)->assertUnauthorized();
});

it('rejects protected tax and tenancy fields and unsupported paper sizes', function () {
    $response = $this->withToken($this->alphaOwnerToken)->putJson('https://alpha.bizismart.test/api/v1/settings/business',
        [...$this->profile, 'business_id' => $this->beta->publicId(), 'vat_rate' => 0, 'paper_width' => '60'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
    $rejected = collect($response->json('error.details.errors'))->keys();
    expect($rejected)->toContain('business_id', 'vat_rate', 'paper_width');
    expect($this->alpha->fresh()->name)->toBe('alpha');
});
