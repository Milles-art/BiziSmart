<?php

use App\Models\{Branch, Business, Plan, TillSession, User};
use App\Services\StaffRoleService;
use App\Tenancy\TenantContext;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    $this->context = app(TenantContext::class);
    $plan = Plan::create(['code' => 'repair', 'name' => 'Repair', 'price_monthly' => 0, 'limits' => [], 'features' => []]);
    foreach (['alpha', 'beta'] as $slug) {
        $this->$slug = Business::create(['name' => $slug, 'subdomain' => $slug, 'plan_id' => $plan->id, 'owner_phone' => '0712345678']);
        $this->context->run($this->$slug, function () use ($slug) {
            app(StaffRoleService::class)->provision();
            $owner = User::create(['name' => 'Owner', 'phone' => '0712345678', 'pin_hash' => '654321', 'must_change_pin' => false]);
            $owner->totp_secret = 'JBSWY3DPEHPK3PXP'; $owner->totp_confirmed_at = now(); $owner->save();
            app(StaffRoleService::class)->assign($owner, 'owner');
            $this->{$slug.'Owner'} = $owner;
            $this->{$slug.'Branch'} = Branch::create(['name' => 'Main']);
        });
    }
});
afterEach(function () { $this->context->clear(); });

it('inspects without mutation then repairs only the named tenant and revokes old credentials exactly once', function () {
    $hash = $this->alphaOwner->pin_hash;
    $token = $this->context->run($this->alpha, fn () => $this->alphaOwner->createToken('repair-check')->accessToken);
    $this->artisan('bizismart:owner-branch-repair alpha')->expectsOutputToContain('Inspection only')->assertSuccessful();
    $this->context->run($this->alpha, function () { expect($this->alphaOwner->fresh()->branch_id)->toBeNull(); });
    $this->context->run($this->alpha, fn () => expect(\App\Models\PersonalAccessToken::whereKey($token->id)->exists())->toBeTrue());
    $this->artisan('bizismart:owner-branch-repair alpha --apply')->expectsOutputToContain('Sign in again')->assertSuccessful();
    $this->context->run($this->alpha, function () use ($hash) {
        $owner = $this->alphaOwner->fresh();
        expect($owner->branch_id)->toBe($this->alphaBranch->id)->and($owner->pin_hash)->toBe($hash)
            ->and($owner->totp_secret)->toBe('JBSWY3DPEHPK3PXP');
        expect($owner->tokens()->exists())->toBeFalse();
        expect(\App\Models\Activity::where('event', 'owner_branch_repaired')->count())->toBe(1);
    });
    $this->context->run($this->beta, fn () => expect($this->betaOwner->fresh()->branch_id)->toBeNull());
    $this->artisan('bizismart:owner-branch-repair alpha --apply')->expectsOutputToContain('already has')->assertSuccessful();
    $this->context->run($this->alpha, fn () => expect(\App\Models\Activity::where('event', 'owner_branch_repaired')->count())->toBe(1));
    expect($this->context->id())->toBeNull();
});

it('refuses ambiguous branches or owners without changing assignments', function (string $ambiguity) {
    $this->context->run($this->alpha, function () use ($ambiguity) {
        if ($ambiguity === 'branch') { Branch::create(['name' => 'Second']); }
        else {
            $owner = User::create(['name' => 'Second owner', 'phone' => '0712345679', 'pin_hash' => '654321']);
            app(StaffRoleService::class)->assign($owner, 'owner');
        }
    });
    $this->artisan('bizismart:owner-branch-repair alpha --apply')->expectsOutputToContain('Exactly one')->assertFailed();
    $this->context->run($this->alpha, fn () => expect($this->alphaOwner->fresh()->branch_id)->toBeNull());
})->with(['branch', 'owner']);

it('refuses a missing or inactive tenant and will not use another tenants branch', function () {
    $this->context->run($this->alpha, fn () => $this->alphaBranch->update(['is_active' => false]));
    $this->artisan('bizismart:owner-branch-repair alpha --apply')->expectsOutputToContain('Exactly one active branch')->assertFailed();
    $this->artisan('bizismart:owner-branch-repair missing --apply')->expectsOutputToContain('not found')->assertFailed();
    $this->alpha->update(['is_active' => false]);
    $this->artisan('bizismart:owner-branch-repair alpha --apply')->expectsOutputToContain('not found')->assertFailed();
});

it('refuses an open till instead of moving an active cash session', function () {
    $this->context->run($this->alpha, function () {
        TillSession::create(['branch_id' => $this->alphaBranch->id, 'cashier_id' => $this->alphaOwner->id,
            'opening_float' => 0, 'status' => 'open', 'opened_at' => now(), 'revision' => 0]);
    });
    $this->artisan('bizismart:owner-branch-repair alpha --apply')->expectsOutputToContain('open till')->assertFailed();
    $this->context->run($this->alpha, fn () => expect($this->alphaOwner->fresh()->branch_id)->toBeNull());
});

it('preserves an existing assignment and its authenticated sessions', function () {
    $token = $this->context->run($this->alpha, function () {
        $this->alphaOwner->update(['branch_id' => $this->alphaBranch->id]);
        return $this->alphaOwner->createToken('already-assigned')->accessToken;
    });
    $this->artisan('bizismart:owner-branch-repair alpha --apply')->expectsOutputToContain('already has')->assertSuccessful();
    $this->context->run($this->alpha, function () use ($token) {
        expect($this->alphaOwner->fresh()->branch_id)->toBe($this->alphaBranch->id);
        expect($this->alphaOwner->tokens()->whereKey($token->id)->exists())->toBeTrue();
        expect(\App\Models\Activity::where('event', 'owner_branch_repaired')->count())->toBe(0);
    });
});
