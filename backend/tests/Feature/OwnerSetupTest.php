<?php

use App\Models\Business;
use App\Models\Device;
use App\Models\OwnerInvitation;
use App\Models\Plan;
use App\Models\User;
use App\Services\OwnerSetupService;
use App\Services\StaffRoleService;
use App\Tenancy\TenantContext;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $plan = Plan::create(['code' => 'setup', 'name' => 'Setup', 'price_monthly' => 0, 'limits' => [], 'features' => []]);
    foreach (['alpha', 'beta'] as $label) {
        $this->{$label} = Business::create(['name' => 'Fresh Choice Supermarket', 'subdomain' => $label, 'plan_id' => $plan->id, 'owner_phone' => '0700000001']);
    }
    $this->invite = $this->context->run($this->alpha, fn () => app(OwnerSetupService::class)->invite());
    $this->input = ['invitation_token' => $this->invite, 'name' => 'Private shop owner', 'phone' => '0712345678',
        'pin' => '987654', 'device_uid' => 'owner-browser-01', 'device_name' => 'Owner browser'];
});

afterEach(function () { $this->context->clear(); $this->travelBack(); });

it('assigns the sole active tenant branch during initial setup so the enrolled owner can open a till', function () {
    $this->alpha->plan->update(['limits' => ['tills' => 1]]);
    $branch = $this->context->run($this->alpha, function () {
        \App\Models\Branch::create(['name' => 'Inactive', 'is_active' => false]);
        return \App\Models\Branch::create(['name' => 'Main']);
    });
    $this->context->run($this->beta, fn () => \App\Models\Branch::create(['name' => 'Other shop']));
    $setup = $this->postJson('http://alpha.bizismart.test/api/v1/auth/owner/setup', $this->input)->assertStatus(202);
    $this->context->run($this->alpha, fn () => expect(User::firstOrFail()->branch_id)->toBe($branch->id));
    $verified = $this->postJson('http://alpha.bizismart.test/api/v1/auth/totp/verify', [
        'challenge_token' => $setup->json('data.challenge_token'),
        'code' => app(Google2FA::class)->getCurrentOtp($setup->json('data.setup_secret')),
    ])->assertOk();
    $this->withToken($verified->json('data.token'))->getJson('http://alpha.bizismart.test/api/v1/till-sessions/current')
        ->assertOk()->assertJsonPath('data.branch.id', $branch->publicId())->assertJsonPath('data.can_open', true);
    $this->postJson('http://alpha.bizismart.test/api/v1/till-sessions/open', [
        'operation_id' => (string) \Illuminate\Support\Str::uuid(), 'branch_id' => $branch->publicId(), 'opening_float' => 0,
    ])->assertCreated()->assertJsonPath('data.till.branch_id', $branch->publicId());
});

it('does not guess an initial assignment between active branches or use a foreign branch', function (bool $ambiguous) {
    $this->context->run($this->beta, fn () => \App\Models\Branch::create(['name' => 'Foreign branch']));
    if ($ambiguous) {
        $this->context->run($this->alpha, function () {
            \App\Models\Branch::create(['name' => 'One']); \App\Models\Branch::create(['name' => 'Two']);
        });
    }
    $this->postJson('http://alpha.bizismart.test/api/v1/auth/owner/setup', $this->input)->assertStatus(202);
    $this->context->run($this->alpha, fn () => expect(User::firstOrFail()->branch_id)->toBeNull());
})->with([true, false]);

it('requires a tenant scoped invite and stores only its digest', function () {
    $this->context->run($this->alpha, function () {
        $invite = OwnerInvitation::firstOrFail();
        expect($invite->token_hash)->toBe(hash('sha256', $this->invite));
        expect($invite->toJson())->not->toContain($this->invite);
    });
    $this->postJson('http://beta.bizismart.test/api/v1/auth/owner/setup', $this->input)->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_INVITATION');
    $this->postJson('http://alpha.bizismart.test/api/v1/auth/owner/setup', [...$this->input, 'invitation_token' => str_repeat('a', 64)])
        ->assertUnauthorized();
    $this->postJson('http://alpha.bizismart.test/api/v1/auth/owner/setup', [...$this->input, 'invitation_token' => 'bad'])->assertUnprocessable();
});

it('rejects expired and replaced invitations', function () {
    $this->travel(91)->minutes();
    $this->postJson('http://alpha.bizismart.test/api/v1/auth/owner/setup', $this->input)->assertUnauthorized();
    $this->travelBack();
    $this->context->run($this->alpha, fn () => app(OwnerSetupService::class)->invite());
    $this->postJson('http://alpha.bizismart.test/api/v1/auth/owner/setup', $this->input)->assertUnauthorized();
});

it('creates an encrypted owner with a hashed private PIN but requires authenticator enrollment before access', function () {
    $response = $this->postJson('http://alpha.bizismart.test/api/v1/auth/owner/setup', $this->input)
        ->assertStatus(202)->assertJsonPath('data.next_step', 'enroll_totp')->assertJsonMissingPath('data.token')
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->context->run($this->alpha, function () {
        $owner = User::firstOrFail();
        expect(password_verify('987654', $owner->pin_hash))->toBeTrue();
        expect($owner->getRawOriginal('phone'))->not->toBe('255712345678');
        expect($owner->getRawOriginal('name'))->not->toBe('Private shop owner');
        expect($owner->must_change_pin)->toBeFalse()->and($owner->tokens()->count())->toBe(0);
        expect(OwnerInvitation::firstOrFail()->consumed_at)->not->toBeNull();
        expect($this->alpha->fresh()->owner_phone)->toBe('255712345678');
        expect(Device::firstOrFail()->status)->toBe('pending');
    });
    $code = app(Google2FA::class)->getCurrentOtp($response->json('data.setup_secret'));
    $verified = $this->postJson('http://alpha.bizismart.test/api/v1/auth/totp/verify', [
        'challenge_token' => $response->json('data.challenge_token'), 'code' => $code,
    ])->assertOk()->assertJsonPath('data.user.role', 'owner');
    $this->withToken($verified->json('data.token'))->getJson('http://alpha.bizismart.test/api/v1/me')
        ->assertOk()->assertJsonPath('data.business.name', 'Fresh Choice Supermarket');
    $this->postJson('http://alpha.bizismart.test/api/v1/auth/owner/setup', $this->input)->assertStatus(409);
});

it('never uses initial onboarding to recover an inactive operational owner', function () {
    $this->context->run($this->alpha, function () {
        app(StaffRoleService::class)->provision();
        $owner = User::create(['name' => 'Owner', 'phone' => '0700000002', 'pin_hash' => '1234', 'is_active' => false]);
        app(StaffRoleService::class)->assign($owner, 'owner');
    });
    $this->postJson('http://alpha.bizismart.test/api/v1/auth/owner/setup', $this->input)
        ->assertStatus(409)->assertJsonPath('error.code', 'OWNER_ALREADY_CONFIGURED');
});

it('converts only untouched disabled scaffold accounts without recreating them on subsequent seeding', function () {
    $this->seed(Database\Seeders\DemoSupermarketSeeder::class);
    $shop = Business::where('subdomain', 'demo-alpha')->firstOrFail();
    $token = $this->context->run($shop, fn () => app(OwnerSetupService::class)->invite());
    $this->postJson('http://demo-alpha.bizismart.test/api/v1/auth/owner/setup', [...$this->input, 'invitation_token' => $token])->assertStatus(202);
    $this->seed(Database\Seeders\DemoSupermarketSeeder::class);
    $this->context->run($shop, function () {
        expect(User::count())->toBe(1)->and(User::first()->name)->toBe('Private shop owner');
        expect(Device::where('device_uid', 'demo-alpha-demo-device')->exists())->toBeFalse();
    });
});

it('rejects duplicate canonical staff phones without consuming the invitation', function () {
    $this->context->run($this->alpha, fn () => User::create(['name' => 'Cashier', 'phone' => '+255712345678', 'pin_hash' => '1234']));
    $this->postJson('http://alpha.bizismart.test/api/v1/auth/owner/setup', $this->input)->assertUnprocessable();
    $this->context->run($this->alpha, fn () => expect(OwnerInvitation::firstOrFail()->consumed_at)->toBeNull());
});
