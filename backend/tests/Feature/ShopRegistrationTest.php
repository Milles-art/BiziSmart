<?php

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Device;
use App\Models\Plan;
use App\Models\User;
use App\Services\ShopRegistrationService;
use App\Services\StaffRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    Plan::create(['code' => 'free', 'name' => 'Free', 'price_monthly' => 0, 'limits' => [], 'features' => []]);
    $this->payload = ['shop_name' => 'Fresh Choice Supermarket', 'name' => 'Neema Mwakalinga', 'phone' => '0714000001',
        'email' => 'owner@freshchoice.test', 'password' => 'a long private password', 'password_confirmation' => 'a long private password',
        'device_uid' => 'signup-device-01', 'device_name' => 'Owner phone'];
});

afterEach(function () { $this->context->clear(); });

function registrationUrl(string $shop = 'fresh-choice', string $path = 'register'): string
{
    return 'https://'.$shop.'.bizismart.test/api/v1/auth/email/'.$path;
}

it('registers a never-used shop address and signs the new owner straight in', function () {
    $this->postJson(registrationUrl(), $this->payload)->assertOk()
        ->assertJsonPath('data.user.role', 'owner')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonMissingPath('data.user.email');

    $business = Business::where('subdomain', 'fresh-choice')->firstOrFail();
    expect($business->name)->toBe('Fresh Choice Supermarket')
        ->and($business->plan_id)->toBe(Plan::where('code', 'free')->value('id'))
        ->and($business->is_active)->toBeTrue();

    $this->context->run($business, function () {
        $owner = User::firstOrFail();
        expect($owner->name)->toBe('Neema Mwakalinga')
            ->and($owner->getRoleNames()->all())->toBe(['owner'])
            ->and($owner->is_active)->toBeTrue()
            ->and($owner->totp_secret)->toBeNull()
            ->and($owner->must_change_pin)->toBeFalse();
        expect(Hash::check('a long private password', $owner->password_hash))->toBeTrue();
        expect($owner->getRawOriginal('email'))->not->toContain('owner@freshchoice.test');
        // No usable PIN exists and the first device is trusted, not left pending.
        expect(Hash::check('0714000001', $owner->pin_hash))->toBeFalse();
        expect(Branch::first()->name)->toBe('Main Branch');
        expect(Device::first()->status)->toBe('approved');
        expect(Device::first()->user_id)->toBe($owner->id);
        expect(User::count())->toBe(1);
    });
});

it('never takes the tenant from the request body', function () {
    $this->postJson(registrationUrl(), $this->payload + ['subdomain' => 'someone-else'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    $this->postJson(registrationUrl(), $this->payload + ['business_id' => 7])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    expect(Business::count())->toBe(0);
});

it('refuses a shop address that already belongs to a business', function () {
    Business::create(['name' => 'Existing', 'subdomain' => 'fresh-choice', 'owner_phone' => '0714000009', 'plan_id' => Plan::where('code', 'free')->value('id')]);

    $this->postJson(registrationUrl(), $this->payload)->assertStatus(409)->assertJsonPath('error.code', 'SHOP_ALREADY_EXISTS');

    expect(Business::where('subdomain', 'fresh-choice')->count())->toBe(1)
        ->and(User::count())->toBe(0)
        ->and(Business::where('subdomain', 'fresh-choice')->firstOrFail()->name)->toBe('Existing');
});

it('keeps every other route hidden on an unregistered shop address', function () {
    $this->getJson('https://fresh-choice.bizismart.test/api/v1/me')->assertStatus(404);
    $this->postJson('https://fresh-choice.bizismart.test/api/v1/auth/email/login', $this->payload)->assertStatus(404);
    $this->postJson('https://fresh-choice.bizismart.test/api/v1/products', [])->assertStatus(404);
});

it('reports a shop address as available only while it is unclaimed', function () {
    $this->getJson(registrationUrl('fresh-choice', 'register/status'))->assertOk()->assertJsonPath('data.available', true);

    $this->postJson(registrationUrl(), $this->payload)->assertOk();

    $this->getJson(registrationUrl('fresh-choice', 'register/status'))->assertOk()->assertJsonPath('data.available', false);
});

it('rejects weak passwords, malformed phone numbers and reserved hosts', function () {
    $this->postJson(registrationUrl(), array_merge($this->payload, ['password' => 'short']))->assertStatus(422);
    $this->postJson(registrationUrl(), array_merge($this->payload, ['password_confirmation' => 'different value here']))->assertStatus(422);
    $this->postJson(registrationUrl(), array_merge($this->payload, ['phone' => 'not-a-number']))->assertStatus(422);
    $this->postJson(registrationUrl(), array_merge($this->payload, ['email' => 'not-an-email']))->assertStatus(422);

    foreach (['admin', 'api', 'www'] as $reserved) {
        $this->getJson('https://'.$reserved.'.bizismart.test/api/v1/auth/email/register/status')->assertStatus(404);
    }
    $this->getJson('https://fresh-choice.example.test/api/v1/auth/email/register/status')->assertStatus(404);

    expect(Business::count())->toBe(0);
});

it('keeps the new shop isolated from every other tenant', function () {
    $other = Business::create(['name' => 'Other Shop', 'subdomain' => 'other-shop', 'owner_phone' => '0714000002', 'plan_id' => Plan::where('code', 'free')->value('id')]);
    $this->context->run($other, function () {
        app(StaffRoleService::class)->provision();
        $branch = Branch::create(['name' => 'Main Branch']);
        $owner = User::create(['name' => 'Other Owner', 'phone' => '0714000002', 'pin_hash' => '1234', 'branch_id' => $branch->id,
            'must_change_pin' => false, 'email' => 'owner@freshchoice.test', 'password_hash' => 'a long private password']);
        app(StaffRoleService::class)->assign($owner, 'owner');
    });

    $this->postJson(registrationUrl(), $this->payload)->assertOk();

    // The same email in a different shop is valid; neither tenant can see the other.
    $registered = Business::where('subdomain', 'fresh-choice')->firstOrFail();
    $this->context->run($registered, function () use ($other, $registered) {
        expect(User::count())->toBe(1)
            ->and(User::forEmail('owner@freshchoice.test')->count())->toBe(1)
            ->and(User::first()->business_id)->toBe($registered->id)
            ->and(User::first()->business_id)->not->toBe($other->id);
    });
    $this->context->run($other, function () use ($registered, $other) {
        expect(User::count())->toBe(1)
            ->and(User::first()->business_id)->toBe($other->id)
            ->and(User::first()->business_id)->not->toBe($registered->id);
    });
});

it('refuses a second registration for a host that already has an active owner', function () {
    $this->postJson(registrationUrl(), $this->payload)->assertOk();

    $this->postJson(registrationUrl(), array_merge($this->payload, ['email' => 'second@freshchoice.test']))->assertStatus(409);

    $this->context->run(Business::where('subdomain', 'fresh-choice')->firstOrFail(), function () {
        expect(User::count())->toBe(1);
    });
});

it('refuses to create a shop for a reserved or malformed host', function () {
    $service = app(ShopRegistrationService::class);
    foreach (['ADMIN.bizismart.test', 'api.bizismart.test', 'bizismart.test', 'evil.example.test'] as $host) {
        try { $service->subdomainFor($host); test()->fail('Expected a reserved host refusal.'); }
        catch (ApiException $e) { expect($e->errorCode)->toBe('NOT_FOUND'); }
    }
    expect($service->subdomainFor('Fresh-Choice.BiziSmart.Test'))->toBe('fresh-choice');
    expect(Business::count())->toBe(0);
});