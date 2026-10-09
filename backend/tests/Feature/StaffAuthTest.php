<?php

use App\Models\AuthChallenge;
use App\Models\Activity;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\Plan;
use App\Models\User;
use App\Services\StaffAuthService;
use App\Services\StaffRoleService;
use App\Support\StaffPhone;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $this->auth = app(StaffAuthService::class);
    $this->totp = app(Google2FA::class);
    $plan = Plan::create(['code' => 'test', 'name' => 'Test', 'price_monthly' => 0, 'limits' => [], 'features' => []]);
    foreach (['alpha', 'beta'] as $name) {
        $business = Business::create(['name' => ucfirst($name), 'subdomain' => $name, 'plan_id' => $plan->id, 'owner_phone' => '0712345678']);
        $this->{$name} = $business;
        $this->context->run($business, function () use ($name) {
            app(StaffRoleService::class)->provision();
            $branch = Branch::create(['name' => 'Main']);
            $user = User::create(['name' => 'Cashier', 'phone' => '0712345678', 'pin_hash' => '1234', 'branch_id' => $branch->id, 'must_change_pin' => false]);
            app(StaffRoleService::class)->assign($user, 'cashier');
            $device = Device::create(['name' => 'Till one', 'device_uid' => 'till-device-01', 'user_id' => $user->id, 'status' => 'approved']);
            $this->{'user'.ucfirst($name)} = $user;
            $this->{'device'.ucfirst($name)} = $device;
        });
    }
    $this->login = ['phone' => '0712345678', 'pin' => '1234', 'device_uid' => 'till-device-01', 'device_name' => 'Till one'];
});

afterEach(function () { $this->context->clear(); $this->travelBack(); });

function authUrl(string $path, string $shop = 'alpha'): string { return 'http://'.$shop.'.bizismart.test/api/v1/'.$path; }

function authOwner($test, bool $enrolled = true): User
{
    return $test->context->run($test->alpha, function () use ($test, $enrolled) {
        $owner = User::create(['name' => 'Owner', 'phone' => '0700000001', 'pin_hash' => '9876', 'must_change_pin' => false]);
        app(StaffRoleService::class)->assign($owner, 'owner');
        if ($enrolled) {
            $owner->totp_secret = $test->totp->generateSecretKey();
            $owner->totp_confirmed_at = now();
            $owner->save();
        }
        return $owner;
    });
}

function authBoundToken($test, User $user): string
{
    return $test->context->run($test->alpha, function () use ($test, $user) {
        $token = $user->createToken('fixture', ['*'], now()->addDays(30));
        $token->accessToken->device_id = $test->deviceAlpha->id;
        $token->accessToken->save();
        return $token->plainTextToken;
    });
}

it('signs in with canonical phone variants and returns only public IDs and approved permissions', function (string $phone) {
    $response = $this->postJson(authUrl('auth/login'), [...$this->login, 'phone' => $phone]);
    $response->assertOk()->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.user.role', 'cashier')
        ->assertJsonPath('data.user.permissions', ['manage_customers', 'manage_till', 'sell'])
        ->assertJsonMissingPath('data.user.phone')->assertJsonMissingPath('data.user.business_id')
        ->assertJsonMissingPath('data.user.pin_hash');
    expect($response->json('data.user.id'))->toStartWith('usr_');
    expect($response->json('data.user.branch_id'))->toStartWith('br_');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $token = $response->json('data.token');
    $this->withToken($token)->getJson(authUrl('me'))->assertOk()->assertJsonPath('data.business.name', 'Alpha');
    $this->getJson(authUrl('me', 'beta'))->assertUnauthorized();
    $this->context->run($this->alpha, function () {
        $token = PersonalAccessToken::first();
        expect($token->device_id)->toBe($this->deviceAlpha->id);
        expect($token->expires_at->diffInDays(now(), true))->toBeGreaterThan(29);
        expect($this->userAlpha->fresh()->phone)->toBe('255712345678');
    });
})->with(['0712345678', '255712345678', '+255712345678', '0712 345-678']);

it('uses the same invalid-PIN response for unknown, disabled and incorrect credentials', function () {
    $wrong = $this->postJson(authUrl('auth/login'), [...$this->login, 'pin' => '0000'])->assertUnauthorized()->json('error');
    $unknown = $this->postJson(authUrl('auth/login'), [...$this->login, 'phone' => '0700000002'])->assertUnauthorized()->json('error');
    $this->context->run($this->alpha, fn () => $this->userAlpha->update(['is_active' => false]));
    $disabled = $this->postJson(authUrl('auth/login'), $this->login)->assertUnauthorized()->json('error');
    expect($wrong)->toBe($unknown)->toBe($disabled);
});

it('persists five failures, locks for 15 minutes and allows sign-in after expiry', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson(authUrl('auth/login'), [...$this->login, 'pin' => '0000'])
            ->assertStatus($i === 4 ? 423 : 401)->assertJsonPath('error.code', $i === 4 ? 'ACCOUNT_LOCKED' : 'INVALID_PIN');
    }
    $this->context->run($this->alpha, function () {
        $user = $this->userAlpha->fresh();
        expect($user->failed_pin_attempts)->toBe(5)->and($user->locked_until->isFuture())->toBeTrue();
        try { $this->auth->login($this->login, '127.0.0.1'); $this->fail('A lock cannot be bypassed with the correct PIN.'); }
        catch (App\Exceptions\ApiException $e) { expect($e->errorCode)->toBe('ACCOUNT_LOCKED'); }
    });
    $this->travel(16)->minutes();
    $this->postJson(authUrl('auth/login'), $this->login)->assertOk();
});

it('throttles canonical phone variants together and returns retry headers', function () {
    for ($i = 0; $i < 5; $i++) { $this->postJson(authUrl('auth/login'), $this->login)->assertOk(); }
    $this->postJson(authUrl('auth/login'), [...$this->login, 'phone' => '+255712345678'])
        ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
});

it('creates a pending device and permits sign-in only after owner approval', function () {
    $owner = authOwner($this);
    $pendingLogin = [...$this->login, 'device_uid' => 'new-device-01'];
    $this->postJson(authUrl('auth/login'), $pendingLogin)->assertForbidden()->assertJsonPath('error.code', 'DEVICE_NOT_APPROVED');
    $device = $this->context->run($this->alpha, fn () => Device::where('device_uid', 'new-device-01')->firstOrFail());
    expect($device->status)->toBe('pending');
    $this->withToken(authBoundToken($this, $owner))->postJson(authUrl('devices/'.$device->publicId().'/approve'))->assertOk();
    $this->withHeader('Authorization', '')->postJson(authUrl('auth/login'), $pendingLogin)->assertOk();
});

it('keeps device administration owner-only and rejects foreign or numeric device IDs', function () {
    $cashierToken = $this->postJson(authUrl('auth/login'), $this->login)->json('data.token');
    $this->withToken($cashierToken)->getJson(authUrl('devices'))->assertForbidden()->assertJsonPath('error.code', 'PERMISSION_DENIED');
    $owner = authOwner($this);
    $this->withToken(authBoundToken($this, $owner))->postJson(authUrl('devices/'.$this->deviceBeta->publicId().'/approve'))->assertNotFound();
    $this->postJson(authUrl('devices/'.$this->deviceAlpha->id.'/approve'))->assertNotFound();
});

it('blocks a shared device and revokes all its staff tokens and pending challenges', function () {
    $cashierToken = $this->postJson(authUrl('auth/login'), $this->login)->json('data.token');
    $owner = authOwner($this);
    $ownerToken = authBoundToken($this, $owner);
    $this->withToken($ownerToken)->postJson(authUrl('devices/'.$this->deviceAlpha->publicId().'/block'))->assertOk();
    $this->withToken($cashierToken)->getJson(authUrl('me'))->assertUnauthorized();
    $this->withToken($ownerToken)->getJson(authUrl('me'))->assertUnauthorized();
    $this->context->run($this->alpha, fn () => expect(PersonalAccessToken::count())->toBe(0));
    $this->withHeader('Authorization', '')->postJson(authUrl('auth/login'), $this->login)->assertForbidden();
});

it('requires PIN change without granting a token and consumes its challenge once', function () {
    $this->context->run($this->alpha, fn () => $this->userAlpha->update(['must_change_pin' => true]));
    $response = $this->postJson(authUrl('auth/login'), $this->login)->assertStatus(202)->assertJsonPath('data.next_step', 'change_pin')->assertJsonMissingPath('data.token');
    $plain = $response->json('data.challenge_token');
    $this->withToken($plain)->getJson(authUrl('me'))->assertUnauthorized();
    $this->postJson(authUrl('auth/pin/change'), ['challenge_token' => $plain, 'new_pin' => '1234'])->assertUnprocessable();
    $token = $this->postJson(authUrl('auth/pin/change'), ['challenge_token' => $plain, 'new_pin' => '4321'])->assertOk()->json('data.token');
    $this->postJson(authUrl('auth/pin/change'), ['challenge_token' => $plain, 'new_pin' => '5678'])->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CHALLENGE');
    $this->withToken($token)->getJson(authUrl('me'))->assertOk();
});

it('expires challenges and invalidates older challenges on a new login', function () {
    $this->context->run($this->alpha, fn () => $this->userAlpha->update(['must_change_pin' => true]));
    $first = $this->postJson(authUrl('auth/login'), $this->login)->json('data.challenge_token');
    $second = $this->postJson(authUrl('auth/login'), $this->login)->json('data.challenge_token');
    $this->postJson(authUrl('auth/pin/change'), ['challenge_token' => $first, 'new_pin' => '4321'])->assertUnauthorized();
    $this->travel(6)->minutes();
    $this->postJson(authUrl('auth/pin/change'), ['challenge_token' => $second, 'new_pin' => '4321'])->assertUnauthorized();
});

it('rejects another shops challenge, including when the phone and PIN match', function () {
    $this->context->run($this->alpha, fn () => $this->userAlpha->update(['must_change_pin' => true]));
    $plain = $this->postJson(authUrl('auth/login'), $this->login)->json('data.challenge_token');
    $this->postJson(authUrl('auth/pin/change', 'beta'), ['challenge_token' => $plain, 'new_pin' => '4321'])->assertUnauthorized();
    $this->context->run($this->alpha, fn () => expect(AuthChallenge::count())->toBe(1));
});

it('verifies owner TOTP before forced PIN change and rejects code replay', function () {
    $owner = authOwner($this);
    $this->context->run($this->alpha, fn () => $owner->update(['must_change_pin' => true]));
    $login = [...$this->login, 'phone' => '0700000001', 'pin' => '9876'];
    $plain = $this->postJson(authUrl('auth/login'), $login)->assertStatus(202)->assertJsonPath('data.next_step', 'verify_totp')->json('data.challenge_token');
    $this->postJson(authUrl('auth/pin/change'), ['challenge_token' => $plain, 'new_pin' => '4321'])->assertUnauthorized();
    $code = $this->totp->oathTotp($owner->totp_secret, intdiv(now()->timestamp, 30));
    $this->postJson(authUrl('auth/totp/verify'), ['challenge_token' => $plain, 'code' => $code])->assertStatus(202)->assertJsonPath('data.next_step', 'change_pin');
    $this->context->run($this->alpha, fn () => expect($owner->fresh()->totp_last_step)->toBe(intdiv(now()->timestamp, 30)));
    $this->postJson(authUrl('auth/pin/change'), ['challenge_token' => $plain, 'new_pin' => '4321'])->assertOk();
    $plain2 = $this->postJson(authUrl('auth/login'), [...$login, 'pin' => '4321'])->json('data.challenge_token');
    $this->postJson(authUrl('auth/totp/verify'), ['challenge_token' => $plain2, 'code' => $code])->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_TOTP');
    $this->travel(31)->seconds();
    $freshCode = $this->totp->oathTotp($owner->totp_secret, intdiv(now()->timestamp, 30));
    $this->postJson(authUrl('auth/totp/verify'), ['challenge_token' => $plain2, 'code' => $freshCode])->assertOk();
});

it('enrolls an owners first device only after verifying the setup secret', function () {
    $owner = authOwner($this, false);
    $this->context->run($this->alpha, fn () => $this->deviceAlpha->update(['status' => 'blocked']));
    $login = [...$this->login, 'phone' => '0700000001', 'pin' => '9876', 'device_uid' => 'first-owner-device'];
    $response = $this->postJson(authUrl('auth/login'), $login)->assertStatus(202)->assertJsonPath('data.next_step', 'enroll_totp');
    $plain = $response->json('data.challenge_token');
    $secret = $response->json('data.setup_secret');
    expect($response->json('data.otpauth_uri'))->toStartWith('otpauth://totp/');
    $this->context->run($this->alpha, function () use ($secret) {
        expect(Device::where('device_uid', 'first-owner-device')->first()->status)->toBe('pending');
        expect(PersonalAccessToken::count())->toBe(0);
        expect(DB::table('auth_challenges')->where('business_id', $this->alpha->id)->value('setup_secret'))->not->toBe($secret);
    });
    $code = $this->totp->oathTotp($secret, intdiv(now()->timestamp, 30));
    $this->postJson(authUrl('auth/totp/verify'), ['challenge_token' => $plain, 'code' => $code])->assertOk();
    $this->context->run($this->alpha, function () use ($owner, $secret) {
        expect($owner->fresh()->totp_secret)->toBe($secret);
        expect(Device::where('device_uid', 'first-owner-device')->first()->status)->toBe('approved');
        expect(DB::table('users')->where('business_id', $this->alpha->id)->where('id', $owner->id)->value('totp_secret'))->not->toBe($secret);
    });
    $this->postJson(authUrl('auth/login'), [...$login, 'device_uid' => 'second-owner-device'])->assertForbidden();
});

it('invalidates a challenge after five wrong authenticator codes', function () {
    $owner = authOwner($this);
    $plain = $this->postJson(authUrl('auth/login'), [...$this->login, 'phone' => '0700000001', 'pin' => '9876'])->json('data.challenge_token');
    $valid = $this->totp->oathTotp($owner->totp_secret, intdiv(now()->timestamp, 30));
    // Use a fixed time and a value outside all accepted steps, rather than a probabilistic wrong code.
    $invalid = '000000';
    while ($this->totp->verifyKey($owner->totp_secret, $invalid, 1, intdiv(now()->timestamp, 30)) !== false) {
        $invalid = str_pad((string) ((int) $invalid + 1), 6, '0', STR_PAD_LEFT);
    }
    for ($i = 0; $i < 5; $i++) { $this->postJson(authUrl('auth/totp/verify'), ['challenge_token' => $plain, 'code' => $invalid])->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_TOTP'); }
    $this->postJson(authUrl('auth/totp/verify'), ['challenge_token' => $plain, 'code' => $valid])->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CHALLENGE');
});

it('resets PINs only as owner, revoking tokens and challenges and forcing a different PIN', function () {
    $cashierToken = $this->postJson(authUrl('auth/login'), $this->login)->json('data.token');
    $this->withToken($cashierToken)->postJson(authUrl('auth/pin/reset'), ['user_id' => $this->userAlpha->publicId(), 'new_pin' => '4321'])->assertForbidden();
    $owner = authOwner($this);
    $this->withToken(authBoundToken($this, $owner))->postJson(authUrl('auth/pin/reset'), ['user_id' => $this->userBeta->publicId(), 'new_pin' => '4321'])->assertNotFound();
    $this->postJson(authUrl('auth/pin/reset'), ['user_id' => $this->userAlpha->publicId(), 'new_pin' => '4321'])->assertOk();
    $this->withToken($cashierToken)->getJson(authUrl('me'))->assertUnauthorized();
    $this->withHeader('Authorization', '')->postJson(authUrl('auth/login'), [...$this->login, 'pin' => '4321'])->assertStatus(202)->assertJsonPath('data.next_step', 'change_pin');
});

it('rotates and logs out tokens without permitting old tokens or PIN-change-pending sessions', function () {
    $old = $this->postJson(authUrl('auth/login'), $this->login)->json('data.token');
    $new = $this->withToken($old)->postJson(authUrl('auth/token/rotate'))->assertOk()->json('data.token');
    $this->withToken($old)->getJson(authUrl('me'))->assertUnauthorized();
    $this->withToken($new)->getJson(authUrl('me'))->assertOk();
    $this->postJson(authUrl('auth/logout'))->assertOk();
    $this->getJson(authUrl('me'))->assertUnauthorized();
    $this->context->run($this->alpha, fn () => $this->userAlpha->update(['must_change_pin' => true]));
    $this->withToken(authBoundToken($this, $this->userAlpha))->getJson(authUrl('me'))->assertUnauthorized();
});

it('rejects legacy unbound tokens on real session routes and omits sensitive shop settings', function () {
    $legacy = $this->context->run($this->alpha, fn () => $this->userAlpha->createToken('legacy')->plainTextToken);
    $this->withToken($legacy)->getJson(authUrl('me'))->assertUnauthorized();
    $this->alpha->settings = ['till_variance_limit' => 5000, 'provider_secret' => 'never show'];
    $this->alpha->save();
    $this->withToken(authBoundToken($this, $this->userAlpha))->getJson(authUrl('me'))->assertOk()->assertJsonPath('data.business.settings.till_variance_limit', 5000)->assertDontSee('provider_secret');
    $this->getJson(authUrl('me/features'))->assertOk()->assertJsonPath('data.features', []);
});

it('rejects cross-business challenge and device-token links at the database boundary', function () {
    $this->context->run($this->alpha, function () {
        expect(fn () => AuthChallenge::create(['user_id' => $this->userBeta->id, 'device_id' => $this->deviceAlpha->id, 'token_hash' => hash('sha256', 'probe'), 'expires_at' => now()->addMinutes(5)]))->toThrow(QueryException::class);
        $token = $this->userAlpha->createToken('fixture')->accessToken;
        $token->device_id = $this->deviceBeta->id;
        expect(fn () => $token->save())->toThrow(QueryException::class);
    });
    expect(AuthChallenge::count())->toBe(0);
});

it('rejects invalid phone, PIN, challenge, code and device shapes without echoing inputs', function () {
    $this->postJson(authUrl('auth/login'), [...$this->login, 'phone' => '+254712345678', 'pin' => 'sensitive-pin', 'device_uid' => 'bad'])
        ->assertUnprocessable()->assertDontSee('sensitive-pin')->assertJsonPath('error.code', 'VALIDATION_ERROR');
    $this->postJson(authUrl('auth/totp/verify'), ['challenge_token' => 'secret-invalid', 'code' => 'ABCDEF'])->assertUnprocessable()->assertDontSee('secret-invalid');
});

it('does not grant access from an unassigned role hint or a disabled account', function () {
    $this->context->run($this->alpha, fn () => $this->userAlpha->syncRoles([]));
    $this->postJson(authUrl('auth/login'), $this->login)->assertForbidden()->assertJsonPath('error.code', 'STAFF_ROLE_REQUIRED');
});

it('revokes tokens and challenges when a user is deactivated or deleted', function () {
    $token = $this->postJson(authUrl('auth/login'), $this->login)->json('data.token');
    $this->context->run($this->alpha, function () {
        $this->userAlpha->update(['must_change_pin' => true]);
        $this->auth->login($this->login, '127.0.0.1');
        expect(AuthChallenge::count())->toBe(1);
        $this->userAlpha->update(['is_active' => false]);
        expect(PersonalAccessToken::count())->toBe(0)->and(AuthChallenge::count())->toBe(0);
        $this->userAlpha->update(['is_active' => true, 'must_change_pin' => false]);
        $this->auth->login($this->login, '127.0.0.1');
        expect(PersonalAccessToken::count())->toBe(1);
        $this->userAlpha->delete();
        expect(PersonalAccessToken::count())->toBe(0);
    });
    $this->withToken($token)->getJson(authUrl('me'))->assertUnauthorized();
});

it('encrypts isolated audit history without recording credentials or staff contact data', function () {
    $owner = authOwner($this);
    $token = authBoundToken($this, $owner);
    $this->withToken($token)->postJson(authUrl('auth/pin/reset'), ['user_id' => $this->userAlpha->publicId(), 'new_pin' => '4321'])->assertOk();
    $this->context->run($this->alpha, function () use ($owner) {
        $event = Activity::where('event', 'pin_reset')->firstOrFail();
        expect($event->causer_id)->toBe($owner->id);
        expect($event->properties->get('device_id'))->toBe($this->deviceAlpha->publicId());
        expect($event->properties->get('ip'))->toBe('127.0.0.1');
        foreach (Activity::all() as $activity) {
            $properties = $activity->properties->toJson();
            expect($properties)->not->toContain('pin_hash', 'totp_secret', 'setup_secret', 'phone_lookup', '4321', '9876');
        }
        $raw = DB::table('activity_log')->where('business_id', $this->alpha->id)->where('id', $event->id)->value('properties');
        expect($raw)->not->toBe($event->properties->toJson());
        expect($raw)->not->toContain('127.0.0.1');
        expect(fn () => activity()->performedOn($this->userBeta)->log('Foreign subject'))->toThrow(LogicException::class);
        expect(fn () => activity()->performedOn($this->userAlpha)->causedBy($this->userBeta)->log('Foreign actor'))->toThrow(LogicException::class);
    });
    expect(Activity::count())->toBe(0);
    $this->context->run($this->beta, fn () => expect(Activity::where('event', 'pin_reset')->count())->toBe(0));
});
