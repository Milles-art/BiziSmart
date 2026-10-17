<?php

use App\Exceptions\ApiException;
use App\Models\AuthChallenge;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Device;
use App\Models\EmailCredentialToken;
use App\Models\OwnerInvitation;
use App\Models\Plan;
use App\Models\User;
use App\Services\EmailAuthService;
use App\Services\StaffRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $this->emailAuth = app(EmailAuthService::class);
    $plan = Plan::create(['code' => 'email-auth', 'name' => 'Email auth', 'price_monthly' => 0, 'limits' => [], 'features' => []]);
    foreach (['alpha', 'beta'] as $name) {
        $business = Business::create(['name' => ucfirst($name), 'subdomain' => $name, 'plan_id' => $plan->id, 'owner_phone' => '0712345678']);
        $this->{$name} = $business;
        $this->context->run($business, function () use ($name) {
            app(StaffRoleService::class)->provision();
            $branch = Branch::create(['name' => 'Main']);
            $user = User::create(['name' => 'Owner', 'phone' => '0712345678', 'pin_hash' => '1234', 'branch_id' => $branch->id,
                'must_change_pin' => false, 'email' => 'owner@example.test', 'password_hash' => 'a private password']);
            app(StaffRoleService::class)->assign($user, 'owner');
            $this->{'owner'.ucfirst($name)} = $user;
        });
    }
    $this->credentials = ['email' => 'OWNER@example.test', 'password' => 'a private password', 'device_uid' => 'email-till-01', 'device_name' => 'Main PC'];
});

afterEach(function () { $this->context->clear(); $this->travelBack(); });

function emailAuthUrl(string $path, string $shop = 'alpha'): string { return 'https://'.$shop.'.bizismart.test/api/v1/auth/email/'.$path; }

function emailAuthFailure(callable $operation, string $code): void
{
    try { $operation(); test()->fail('Expected authentication refusal.'); }
    catch (ApiException $e) { expect($e->errorCode)->toBe($code); }
}

function emailAuthMailConfig(): void
{
    config(['mail.default' => 'smtp', 'mail.from.address' => 'security@bizismart.test',
        'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.bizismart.com', 'port' => 587, 'username' => 'mailer', 'password' => 'fixture-only']]);
}

it('logs an owner in with email and password without a TOTP secret and encrypts the email', function () {
    $this->postJson(emailAuthUrl('login'), $this->credentials)->assertOk()->assertJsonPath('data.user.role', 'owner')
        ->assertJsonPath('data.token_type', 'Bearer')->assertJsonMissingPath('data.next_step')->assertJsonMissingPath('data.user.email')
        ->assertJsonMissingPath('data.user.password_hash');
    $this->context->run($this->alpha, function () {
        $owner = $this->ownerAlpha->fresh();
        expect($owner->totp_secret)->toBeNull()->and($owner->totp_confirmed_at)->toBeNull();
        expect($owner->getRawOriginal('email'))->not->toContain('owner@example.test');
        expect(Hash::check('a private password', $owner->password_hash))->toBeTrue();
        expect(Device::first()->status)->toBe('approved')->and(AuthChallenge::count())->toBe(0);
    });
});

it('retains owner approval for a second device and refuses legacy PIN login after enrollment', function () {
    $this->postJson(emailAuthUrl('login'), $this->credentials)->assertOk();
    $this->postJson(emailAuthUrl('login'), [...$this->credentials, 'device_uid' => 'email-till-02'])->assertForbidden()->assertJsonPath('error.code', 'DEVICE_NOT_APPROVED');
    $this->postJson('https://alpha.bizismart.test/api/v1/auth/login', ['phone' => '0712345678', 'pin' => '1234', 'device_uid' => 'email-till-01', 'device_name' => 'Main PC'])
        ->assertUnauthorized();
    $this->context->run($this->alpha, fn () => expect(Device::where('device_uid', 'email-till-02')->first()->status)->toBe('pending'));
});

it('lets an email owner without MFA use the shop, open a till and approve with password while refusing the old PIN', function () {
    $this->context->run($this->alpha, fn () => Plan::whereKey($this->alpha->plan_id)->update(['limits' => ['tills' => 2]]));
    $login = $this->postJson(emailAuthUrl('login'), $this->credentials)->assertOk()->json('data.token');
    $this->withToken($login)->getJson('https://alpha.bizismart.test/api/v1/me')->assertOk()->assertJsonPath('data.user.role', 'owner');
    $this->getJson('https://alpha.bizismart.test/api/v1/cash-drops/receivers')->assertOk();
    $branchId = $this->context->run($this->alpha, fn () => Branch::first()->publicId());
    $this->postJson('https://alpha.bizismart.test/api/v1/till-sessions/open', ['operation_id' => (string) Illuminate\Support\Str::uuid(), 'branch_id' => $branchId, 'opening_float' => 0])->assertCreated();
    $approval = ['permission' => 'give_discount', 'operation_id' => (string) Illuminate\Support\Str::uuid(),
        'payload_hash' => App\Services\SupervisorApprovalService::snapshotHash(['branch_id' => $branchId, 'amount' => 100])];
    $this->postJson('https://alpha.bizismart.test/api/v1/auth/supervisor-check', [...$approval, 'email' => 'owner@example.test', 'password' => "a private\0password"])
        ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
    $this->postJson('https://alpha.bizismart.test/api/v1/auth/supervisor-check', [...$approval, 'phone' => '0712345678', 'pin' => '1234'])
        ->assertUnauthorized();
    $this->postJson('https://alpha.bizismart.test/api/v1/auth/supervisor-check', [...$approval, 'email' => 'owner@example.test', 'password' => 'a private password'])
        ->assertOk()->assertJsonPath('data.approved_by', $this->ownerAlpha->publicId())->assertJsonMissingPath('data.token');
});

it('uses tenant scoped email identity and refuses a token on a different shop', function () {
    $response = $this->postJson(emailAuthUrl('login'), $this->credentials)->assertOk();
    $this->withToken($response->json('data.token'))->getJson('https://beta.bizismart.test/api/v1/me')->assertUnauthorized();
    $this->context->run($this->beta, fn () => $this->ownerBeta->update(['password_hash' => 'another password only']));
    $this->withHeader('Authorization', '')->postJson(emailAuthUrl('login', 'beta'), $this->credentials)->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
});

it('persists failed password attempts and blocks even a correct password during the lock', function () {
    $this->context->run($this->alpha, function () {
        for ($i = 0; $i < 5; $i++) {
            emailAuthFailure(fn () => $this->emailAuth->login([...$this->credentials, 'password' => 'wrong'], null), $i === 4 ? 'ACCOUNT_LOCKED' : 'INVALID_CREDENTIALS');
        }
        expect($this->ownerAlpha->fresh()->failed_pin_attempts)->toBe(5);
        emailAuthFailure(fn () => $this->emailAuth->login($this->credentials, null), 'ACCOUNT_LOCKED');
        $this->travel(16)->minutes();
        expect($this->emailAuth->login($this->credentials, null)['status'])->toBe(200);
    });
});

it('requires twelve character confirmed new passwords before initial setup', function () {
    $this->postJson(emailAuthUrl('activate'), ['token' => str_repeat('a', 64), 'email' => 'new@example.test', 'password' => '123456', 'password_confirmation' => '123456'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
    $this->postJson(emailAuthUrl('reset-password'), ['token' => str_repeat('a', 64), 'password' => 'a secure new password', 'password_confirmation' => 'different password'])
        ->assertUnprocessable();
});

it('refuses bcrypt truncation collisions and multibyte passwords exceeding seventy two bytes', function () {
    $this->context->run($this->alpha, fn () => $this->ownerAlpha->update(['password_hash' => str_repeat('x', 72)]));
    foreach ([str_repeat('x', 72).'suffix-one', str_repeat('x', 72).'suffix-two', str_repeat('é', 40)] as $password) {
        $this->postJson(emailAuthUrl('login'), [...$this->credentials, 'password' => $password])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->postJson(emailAuthUrl('reset-password'), ['token' => str_repeat('a', 64), 'password' => $password, 'password_confirmation' => $password])->assertUnprocessable();
    }
    $this->context->run($this->alpha, fn () => expect($this->ownerAlpha->fresh()->failed_pin_attempts)->toBe(0));
});

it('binds activation to one active preexisting user and refuses replay, other tenant and credential replacement', function () {
    $plain = $this->context->run($this->alpha, function () {
        $user = User::create(['name' => 'Cashier', 'phone' => '0700000002', 'pin_hash' => '1234']);
        app(StaffRoleService::class)->assign($user, 'cashier');
        $this->legacyUser = $user;
        return $this->emailAuth->createActivationToken($user);
    });
    $input = ['token' => $plain, 'email' => 'cashier@example.test', 'password' => 'another secure password', 'password_confirmation' => 'another secure password'];
    $this->postJson(emailAuthUrl('activate', 'beta'), $input)->assertUnauthorized();
    $this->postJson(emailAuthUrl('activate'), [...$input, 'phone' => '0712345678', 'user_id' => $this->ownerAlpha->publicId()])->assertOk();
    $this->postJson(emailAuthUrl('activate'), $input)->assertUnauthorized();
    $this->context->run($this->alpha, function () use ($plain) {
        expect($this->legacyUser->fresh()->email)->toBe('cashier@example.test')->and($this->ownerAlpha->fresh()->email)->toBe('owner@example.test');
        expect(EmailCredentialToken::first()->getRawOriginal('token_hash'))->toBe(hash('sha256', $plain));
        emailAuthFailure(fn () => $this->emailAuth->createActivationToken($this->legacyUser->fresh()), 'CREDENTIAL_ENROLLMENT_UNAVAILABLE');
    });
});

it('retains inactive and soft deleted tenant email reservations while allowing another shop to reuse an email', function () {
    $this->context->run($this->alpha, function () {
        $this->ownerAlpha->delete();
        $legacy = User::create(['name' => 'Legacy', 'phone' => '0700000002', 'pin_hash' => '1234']);
        $plain = $this->emailAuth->createActivationToken($legacy);
        emailAuthFailure(fn () => $this->emailAuth->activate(['token' => $plain, 'email' => 'OWNER@example.test', 'password' => 'another secure password']), 'VALIDATION_ERROR');
        expect($legacy->fresh()->password_hash)->toBeNull()->and(EmailCredentialToken::first()->used_at)->toBeNull();
    });
    $this->context->run($this->beta, fn () => expect(User::forEmail('owner@example.test')->count())->toBe(1));
});

it('requires the existing PIN for session enrollment then revokes all sessions and challenges', function () {
    $this->context->run($this->alpha, function () {
        $legacy = User::create(['name' => 'Legacy', 'phone' => '0700000002', 'pin_hash' => '1234']);
        app(StaffRoleService::class)->assign($legacy, 'cashier');
        $device = Device::create(['name' => 'PC', 'device_uid' => 'existing-pc-01', 'user_id' => $legacy->id, 'status' => 'approved']);
        $session = $legacy->createToken('fixture', ['*'], now()->addDay());
        $session->accessToken->device_id = $device->id; $session->accessToken->save();
        $legacy->withAccessToken($session->accessToken);
        AuthChallenge::create(['user_id' => $legacy->id, 'device_id' => $device->id, 'token_hash' => str_repeat('d', 64), 'expires_at' => now()->addMinutes(5)]);
        $till = App\Models\TillSession::create(['branch_id' => Branch::first()->id, 'cashier_id' => $legacy->id,
            'opening_float' => 0, 'opened_at' => now()]);
        $lease = App\Models\OfflineLease::create(['user_id' => $legacy->id, 'device_id' => $device->id, 'till_session_id' => $till->id,
            'secret' => 'fixture-only', 'catalogue' => [], 'vat_rate' => 0, 'issued_at' => now(), 'expires_at' => now()->addHour()]);
        App\Models\SupervisorApproval::create(['requester_id' => $legacy->id, 'approver_id' => $this->ownerAlpha->id,
            'requester_token_id' => $session->accessToken->id, 'device_id' => $device->id, 'permission' => 'apply_discount',
            'operation_id' => (string) Illuminate\Support\Str::uuid(), 'payload_hash' => str_repeat('e', 64), 'token_hash' => str_repeat('f', 64), 'expires_at' => now()->addMinutes(5)]);
        $plain = $this->emailAuth->createActivationToken($legacy);
        emailAuthFailure(fn () => $this->emailAuth->enroll($legacy, ['email' => 'legacy@example.test', 'password' => 'a secure enrollment password', 'current_pin' => '9999']), 'INVALID_CREDENTIALS');
        expect($legacy->fresh()->password_hash)->toBeNull()->and($legacy->tokens()->count())->toBe(1);
        $this->emailAuth->enroll($legacy, ['email' => 'legacy@example.test', 'password' => 'a secure enrollment password', 'current_pin' => '1234']);
        expect($legacy->fresh()->must_change_pin)->toBeFalse()->and($legacy->tokens()->count())->toBe(0)->and(AuthChallenge::count())->toBe(0);
        expect($lease->fresh()->revoked_at)->not->toBeNull()->and(App\Models\SupervisorApproval::count())->toBe(0);
        expect(EmailCredentialToken::where('token_hash', hash('sha256', $plain))->first()->used_at)->not->toBeNull();
    });
});

it('does not create reset links for logging, array or failover mail transports', function () {
    foreach (['log', 'array', 'failover'] as $mailer) {
        config(['mail.default' => $mailer]);
        $known = $this->postJson(emailAuthUrl('forgot-password'), ['email' => 'owner@example.test'])->assertStatus(503)->assertJsonPath('error.code', 'PASSWORD_RESET_UNAVAILABLE')->json('error');
        $unknown = $this->postJson(emailAuthUrl('forgot-password'), ['email' => 'unknown@example.test'])->assertStatus(503)->json('error');
        expect($known)->toBe($unknown);
    }
    $this->context->run($this->alpha, fn () => expect(EmailCredentialToken::count())->toBe(0));
});

it('sends a trusted tenant reset link and consumes it once with session revocation', function () {
    emailAuthMailConfig();
    $sentBody = null;
    Mail::shouldReceive('mailer')->once()->with('smtp')->andReturnSelf();
    Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body, $configure) use (&$sentBody) {
        $sentBody = $body;
        $message = Mockery::mock(Illuminate\Mail\Message::class);
        $message->shouldReceive('to')->once()->with('owner@example.test')->andReturnSelf();
        $message->shouldReceive('subject')->once()->with('Reset your BiziSmart password')->andReturnSelf();
        $configure($message);
    });
    $login = $this->postJson(emailAuthUrl('login'), $this->credentials)->assertOk()->json('data.token');
    $this->postJson(emailAuthUrl('forgot-password'), ['email' => 'owner@example.test'])->assertOk()->assertJsonMissingPath('data.token');
    expect($sentBody)->toContain('https://alpha.bizismart.test/#reset=')->not->toContain('beta.bizismart.test');
    preg_match('/#reset=([a-f0-9]{64})/', $sentBody, $match);
    $input = ['token' => $match[1], 'password' => 'a replacement password', 'password_confirmation' => 'a replacement password'];
    $this->postJson(emailAuthUrl('reset-password', 'beta'), $input)->assertUnauthorized();
    $this->postJson(emailAuthUrl('reset-password'), $input)->assertOk();
    $this->postJson(emailAuthUrl('reset-password'), $input)->assertUnauthorized();
    $this->withToken($login)->getJson('https://alpha.bizismart.test/api/v1/me')->assertUnauthorized();
    $this->context->run($this->alpha, function () {
        expect(Hash::check('a replacement password', $this->ownerAlpha->fresh()->password_hash))->toBeTrue();
        expect(EmailCredentialToken::first()->used_at)->not->toBeNull();
    });
});

it('returns the same public response for unknown email and failed SMTP and retires uncertain tokens', function () {
    emailAuthMailConfig();
    Mail::shouldReceive('mailer')->once()->andReturnSelf();
    Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('fixture SMTP failure contains private details'));
    $known = $this->postJson(emailAuthUrl('forgot-password'), ['email' => 'owner@example.test'])->assertOk()->json('data');
    $unknown = $this->postJson(emailAuthUrl('forgot-password'), ['email' => 'missing@example.test'])->assertOk()->json('data');
    expect($known)->toBe($unknown)->not->toHaveKey('token');
    $this->context->run($this->alpha, fn () => expect(EmailCredentialToken::whereNull('used_at')->count())->toBe(0));
});

it('rejects expired activation links without consuming valid identity or changing credentials', function () {
    $this->context->run($this->alpha, function () {
        $legacy = User::create(['name' => 'Legacy', 'phone' => '0700000002', 'pin_hash' => '1234']);
        $plain = $this->emailAuth->createActivationToken($legacy);
        $this->travel(31)->minutes();
        emailAuthFailure(fn () => $this->emailAuth->activate(['token' => $plain, 'email' => 'legacy@example.test', 'password' => 'a secure enrollment password']), 'INVALID_CREDENTIAL_TOKEN');
        expect($legacy->fresh()->password_hash)->toBeNull();
    });
});

it('rejects expired reset links and invalidates every older credential token when reset succeeds', function () {
    $this->context->run($this->alpha, function () {
        $plain = bin2hex(random_bytes(32));
        $expired = EmailCredentialToken::create(['user_id' => $this->ownerAlpha->id, 'purpose' => 'reset', 'email' => $this->ownerAlpha->email,
            'token_hash' => hash('sha256', $plain), 'expires_at' => now()->subSecond()]);
        emailAuthFailure(fn () => $this->emailAuth->reset(['token' => $plain, 'password' => 'a replacement password']), 'INVALID_CREDENTIAL_TOKEN');
        expect(Hash::check('a private password', $this->ownerAlpha->fresh()->password_hash))->toBeTrue();
        $expired->expires_at = now()->addMinutes(5); $expired->save();
        EmailCredentialToken::create(['user_id' => $this->ownerAlpha->id, 'purpose' => 'activation', 'token_hash' => str_repeat('c', 64), 'expires_at' => now()->addMinutes(5)]);
        $this->emailAuth->reset(['token' => $plain, 'password' => 'a replacement password']);
        expect(EmailCredentialToken::whereNull('used_at')->count())->toBe(0);
    });
});

it('uses the original initial owner invitation and assigns the sole branch without MFA', function () {
    $business = Business::create(['name' => 'New shop', 'subdomain' => 'newshop', 'plan_id' => $this->alpha->plan_id, 'owner_phone' => '0700000003']);
    $plain = $this->context->run($business, function () {
        Branch::create(['name' => 'Main']);
        return app(App\Services\OwnerSetupService::class)->invite();
    });
    $input = [...$this->credentials, 'invitation_token' => $plain, 'name' => 'New Owner', 'email' => 'new@example.test', 'password_confirmation' => $this->credentials['password']];
    $this->postJson(emailAuthUrl('setup', 'newshop'), $input)->assertOk()->assertJsonPath('data.user.role', 'owner')->assertJsonMissingPath('data.next_step');
    $this->context->run($business, function () {
        expect(User::first()->branch_id)->toBe(Branch::first()->id)->and(User::first()->phone)->toBe('255700000003')->and(OwnerInvitation::first()->consumed_at)->not->toBeNull();
    });
    $this->postJson(emailAuthUrl('setup', 'newshop'), $input)->assertStatus(409);
});

it('lets an email-only owner open a till and issue password approvals without historical MFA or PIN fallback', function () {
    Plan::whereKey($this->alpha->plan_id)->update(['limits' => ['tills' => 2]]);
    $login = $this->postJson(emailAuthUrl('login'), $this->credentials)->assertOk();
    $this->withToken($login->json('data.token'));
    $base = 'https://alpha.bizismart.test/api/v1/';
    $this->getJson($base.'me')->assertOk();
    $branchId = $this->context->run($this->alpha, fn () => $this->ownerAlpha->branch->publicId());
    $this->postJson($base.'till-sessions/open', ['operation_id' => (string) Illuminate\Support\Str::uuid(), 'branch_id' => $branchId, 'opening_float' => 1000])->assertSuccessful();
    $grant = ['email' => 'owner@example.test', 'password' => 'a private password', 'permission' => 'adjust_stock',
        'operation_id' => (string) Illuminate\Support\Str::uuid(), 'payload_hash' => str_repeat('a', 64)];
    $this->postJson($base.'auth/supervisor-check', $grant)->assertOk()->assertJsonMissingPath('data.code');
    $this->postJson($base.'auth/supervisor-check', [...$grant, 'password' => 'incorrect password'])->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    $this->getJson($base.'me')->assertOk();
    $this->postJson($base.'auth/supervisor-check', ['phone' => '0712345678', 'pin' => '1234',
        'permission' => 'adjust_stock', 'operation_id' => $grant['operation_id'], 'payload_hash' => $grant['payload_hash']])->assertUnauthorized();
    $this->postJson($base.'auth/login', ['phone' => '0712345678', 'pin' => '1234', 'device_uid' => 'email-till-01', 'device_name' => 'Main PC'])->assertUnauthorized();
});

it('creates email-first staff without a usable temporary PIN and requires private activation', function () {
    Plan::whereKey($this->alpha->plan_id)->update(['limits' => ['users' => 10]]);
    $token = $this->postJson(emailAuthUrl('login'), $this->credentials)->assertOk()->json('data.token');
    $this->withToken($token)->postJson('https://alpha.bizismart.test/api/v1/users', ['name' => 'Email-first cashier', 'phone' => '0711111112', 'role' => 'cashier'])
        ->assertCreated()->assertJsonPath('data.email_login_enabled', false)->assertJsonMissingPath('data.pin_hash');
    $this->postJson('https://alpha.bizismart.test/api/v1/auth/login', ['phone' => '0711111112', 'pin' => '123456', 'device_uid' => 'email-till-01', 'device_name' => 'Main PC'])
        ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_PIN');
});
