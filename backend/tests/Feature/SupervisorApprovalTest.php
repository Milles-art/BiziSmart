<?php

use App\Exceptions\ApiException;
use App\Models\Activity;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Device;
use App\Models\Plan;
use App\Models\SupervisorApproval;
use App\Models\User;
use App\Services\StaffAuthService;
use App\Services\StaffManagementService;
use App\Services\StaffRoleService;
use App\Services\DeviceService;
use App\Services\SupervisorApprovalService;
use App\Tenancy\TenantContext;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $this->service = app(SupervisorApprovalService::class);
    $plan = Plan::create(['code' => 'approval', 'name' => 'Approval', 'price_monthly' => 0, 'limits' => [], 'features' => []]);
    foreach (['alpha', 'beta'] as $label) {
        $business = Business::create(['name' => $label, 'subdomain' => $label, 'plan_id' => $plan->id, 'owner_phone' => '0700000001']);
        $this->{$label} = $business;
        $this->context->run($business, function () use ($label) {
            app(StaffRoleService::class)->provision();
            $branch = Branch::create(['name' => 'Main']);
            $this->{$label.'Branch'} = $branch;
            $device = Device::create(['device_uid' => 'approval-device-01', 'name' => 'Checkout device', 'status' => 'approved']);
            foreach (['owner' => '0700000001', 'manager' => '0700000002', 'cashier' => '0700000003', 'stockkeeper' => '0700000004'] as $role => $phone) {
                $user = User::create(['branch_id' => $branch->id, 'name' => 'Private '.$role, 'phone' => $phone, 'pin_hash' => '1234', 'must_change_pin' => false]);
                app(StaffRoleService::class)->assign($user, $role);
                if ($role === 'owner') {
                    $user->totp_secret = 'JBSWY3DPEHPK3PXP'; $user->totp_confirmed_at = now(); $user->save();
                }
                $token = $user->createToken('approval-test', ['*'], now()->addDays(30));
                $token->accessToken->device_id = $device->id; $token->accessToken->save();
                $user->withAccessToken($token->accessToken);
                $this->{$label.ucfirst($role)} = $user;
                $this->{$label.ucfirst($role).'Token'} = $token->plainTextToken;
            }
            $this->{$label.'Device'} = $device;
        });
    }
    $this->snapshot = ['branch_id' => $this->alphaBranch->publicId(), 'sale_id' => 'sale_synthetic', 'amount' => 1200, 'reason' => 'Customer return', 'items' => [['batch_id' => 'bat_synthetic', 'qty' => '1']]];
    $this->input = ['phone' => '0700000002', 'pin' => '1234', 'permission' => 'refund',
        'operation_id' => 'a05c7c91-5262-4b3c-9101-1c0c8dd4dd6f', 'payload_hash' => SupervisorApprovalService::snapshotHash($this->snapshot)];
    $this->withToken($this->alphaCashierToken);
});

afterEach(function () { $this->context->clear(); $this->travelBack(); });

function approvalUrl(): string { return 'http://alpha.bizismart.test/api/v1/auth/supervisor-check'; }
function issueApproval($test, array $extra = []): string {
    return $test->postJson(approvalUrl(), [...$test->input, ...$extra])->assertOk()->json('data.approval_token');
}
function executeApproval($test, string $token, array $extra = [], ?Closure $operation = null): mixed {
    return $test->context->run($test->alpha, fn () => $test->service->execute($extra['requester'] ?? $test->alphaCashier, $token,
        $extra['permission'] ?? 'refund', $extra['operation_id'] ?? $test->input['operation_id'],
        $extra['snapshot'] ?? $test->snapshot, $operation ?? fn ($approver) => $approver->publicId()));
}

it('issues only scoped operation approvals with digests and public IDs, never session tokens or PINs', function () {
    $response = $this->postJson(approvalUrl(), $this->input)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.approved_by', $this->alphaManager->publicId())->assertJsonMissingPath('data.token');
    $plain = $response->json('data.approval_token');
    $this->context->run($this->alpha, function () use ($plain) {
        $approval = SupervisorApproval::firstOrFail();
        expect($approval->token_hash)->toBe(hash('sha256', $plain));
        expect($approval->toJson())->not->toContain($plain, 'token_hash', 'payload_hash');
        $event = Activity::where('event', 'supervisor_approval_issued')->firstOrFail();
        expect($event->properties->get('permission'))->toBe('refund');
        expect($event->properties->get('operation_id'))->toBe($this->input['operation_id']);
        expect($event->properties->get('approval'))->toStartWith('apr_');
        expect(json_encode($event->properties))->not->toContain($plain, '1234', '0700000002', 'Private manager');
    });
    $this->withToken($plain)->getJson('http://alpha.bizismart.test/api/v1/me')->assertUnauthorized();
});

it('allows each approved manager operation and consumes it once', function ($permission) {
    $token = issueApproval($this, ['permission' => $permission]);
    expect(executeApproval($this, $token, ['permission' => $permission]))->toBe($this->alphaManager->publicId());
    expect(fn () => executeApproval($this, $token, ['permission' => $permission]))->toThrow(ApiException::class);
    $this->context->run($this->alpha, fn () => expect(Activity::where('event', 'supervisor_approval_consumed')->count())->toBe(1));
})->with(['refund', 'void', 'give_discount', 'approve_variance']);

it('requires a full approved-device session, eligible approver and narrow permission vocabulary', function () {
    $this->withToken('invalid')->postJson(approvalUrl(), $this->input)->assertUnauthorized();
    $this->withToken($this->alphaStockkeeperToken)->postJson(approvalUrl(), $this->input)->assertForbidden();
    $this->withToken($this->alphaCashierToken);
    $this->postJson(approvalUrl(), [...$this->input, 'permission' => 'manage_users'])->assertUnprocessable();
    $this->postJson(approvalUrl(), [...$this->input, 'phone' => '0700000003'])->assertForbidden()->assertJsonPath('error.code', 'APPROVER_NOT_AUTHORIZED');
    $this->context->run($this->alpha, function () { $this->alphaManager->must_change_pin = true; $this->alphaManager->save(); });
    $this->postJson(approvalUrl(), $this->input)->assertForbidden();
});

it('requires fresh owner TOTP, rejects replay, and never creates an owner login session', function () {
    $input = [...$this->input, 'phone' => '0700000001'];
    $this->postJson(approvalUrl(), $input)->assertForbidden()->assertJsonPath('error.code', 'TOTP_REQUIRED');
    $code = app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP');
    $this->postJson(approvalUrl(), [...$input, 'code' => $code])->assertOk()->assertJsonMissingPath('data.token');
    $this->postJson(approvalUrl(), [...$input, 'code' => $code])->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_TOTP');
    $this->context->run($this->alpha, fn () => expect($this->alphaOwner->tokens()->count())->toBe(1));
});

it('shares persistent PIN lockout with login without disclosing unknown or disabled accounts', function () {
    $this->postJson(approvalUrl(), [...$this->input, 'phone' => '0799999999'])->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_PIN');
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $response = $this->postJson(approvalUrl(), [...$this->input, 'pin' => '9999']);
        $response->assertStatus($attempt < 5 ? 401 : 423);
    }
    $this->travel(1)->minutes();
    $this->postJson('http://alpha.bizismart.test/api/v1/auth/login', ['phone' => '0700000002', 'pin' => '1234',
        'device_uid' => 'approval-device-01', 'device_name' => 'Checkout'])->assertStatus(423);
});

it('rejects expired, replaced, changed operation and changed payload approvals', function () {
    $token = issueApproval($this);
    expect(fn () => executeApproval($this, $token, ['snapshot' => [...$this->snapshot, 'amount' => 1201]]))->toThrow(ApiException::class);
    expect(fn () => executeApproval($this, $token, ['operation_id' => 'a05c7c91-5262-4b3c-9101-1c0c8dd4dd60']))->toThrow(ApiException::class);
    expect(fn () => executeApproval($this, $token, ['permission' => 'void']))->toThrow(ApiException::class);
    issueApproval($this);
    expect(fn () => executeApproval($this, $token))->toThrow(ApiException::class);
    $token = issueApproval($this);
    $this->travel(5)->minutes();
    expect(fn () => executeApproval($this, $token))->toThrow(ApiException::class);
});

it('binds approval to its requester session, device and shop', function () {
    $token = issueApproval($this);
    expect(fn () => executeApproval($this, $token, ['requester' => $this->alphaManager]))->toThrow(ApiException::class);
    $this->context->run($this->beta, function () use ($token) {
        expect(fn () => $this->service->execute($this->betaCashier, $token, 'refund', $this->input['operation_id'], $this->snapshot, fn () => true))->toThrow(ApiException::class);
    });
    $other = $this->context->run($this->alpha, function () {
        $user = User::findOrFail($this->alphaCashier->id);
        $device = Device::create(['device_uid' => 'approval-device-02', 'name' => 'Other till', 'status' => 'approved']);
        $new = $user->createToken('other-session', ['*'], now()->addDays(30));
        $new->accessToken->device_id = $device->id; $new->accessToken->save();
        return $user->withAccessToken($new->accessToken);
    });
    expect(fn () => executeApproval($this, $token, ['requester' => $other]))->toThrow(ApiException::class);
    expect(executeApproval($this, $token))->toBe($this->alphaManager->publicId());
});

it('rolls approval consumption and domain changes back together when the operation fails', function () {
    $token = issueApproval($this);
    expect(fn () => executeApproval($this, $token, operation: function () {
        Branch::create(['name' => 'Rollback probe']); throw new RuntimeException('Operation failed');
    }))->toThrow(RuntimeException::class);
    $this->context->run($this->alpha, function () {
        expect(Branch::where('name', 'Rollback probe')->exists())->toBeFalse();
        expect(SupervisorApproval::firstOrFail()->consumed_at)->toBeNull();
    });
    expect(executeApproval($this, $token))->toBe($this->alphaManager->publicId());
});

it('rejects recursive reuse inside the same transaction', function () {
    $token = issueApproval($this);
    executeApproval($this, $token, operation: function () use ($token) {
        expect(fn () => executeApproval($this, $token))->toThrow(ApiException::class);
        return true;
    });
});

it('revokes outstanding grants after approver PIN reset, profile change, role replacement and deactivation', function ($change) {
    $token = issueApproval($this);
    $this->context->run($this->alpha, function () use ($change) {
        if ($change === 'pin') app(StaffAuthService::class)->resetPin($this->alphaOwner, $this->alphaManager, '5678');
        if ($change === 'profile') app(StaffManagementService::class)->update($this->alphaOwner, $this->alphaManager, ['name' => 'Changed manager']);
        if ($change === 'role') app(StaffRoleService::class)->assign($this->alphaManager, 'cashier');
        if ($change === 'inactive') { $this->alphaManager->is_active = false; $this->alphaManager->save(); }
        expect(SupervisorApproval::count())->toBe(0);
    });
    expect(fn () => executeApproval($this, $token))->toThrow(ApiException::class);
})->with(['pin', 'profile', 'role', 'inactive']);

it('revokes outstanding grants on requester logout through the composite session foreign key', function () {
    $token = issueApproval($this);
    $this->postJson('http://alpha.bizismart.test/api/v1/auth/logout')->assertOk();
    $this->context->run($this->alpha, fn () => expect(SupervisorApproval::count())->toBe(0));
    expect(fn () => executeApproval($this, $token))->toThrow(ApiException::class);
});

it('revokes grants when the requester session rotates or its shared device is blocked', function ($change) {
    $token = issueApproval($this);
    $this->context->run($this->alpha, function () use ($change) {
        if ($change === 'rotate') app(StaffAuthService::class)->rotate($this->alphaCashier);
        else app(DeviceService::class)->setStatus($this->alphaOwner, $this->alphaDevice, 'blocked');
        expect(SupervisorApproval::count())->toBe(0);
    });
    expect(fn () => executeApproval($this, $token))->toThrow(ApiException::class);
})->with(['rotate', 'block']);

it('returns the same credential error for a disabled supervisor and an unknown phone', function () {
    $this->context->run($this->alpha, function () { $this->alphaManager->is_active = false; $this->alphaManager->save(); });
    $disabled = $this->postJson(approvalUrl(), $this->input)->assertUnauthorized()->json('error');
    $unknown = $this->postJson(approvalUrl(), [...$this->input, 'phone' => '0799999999'])->assertUnauthorized()->json('error');
    expect($disabled)->toBe($unknown);
});

it('hashes canonical exact snapshots while preserving item order and rejecting floats', function () {
    expect(SupervisorApprovalService::snapshotHash(['amount' => 1200, 'items' => [['qty' => '1', 'batch' => 'bat_1']]]))
        ->toBe(SupervisorApprovalService::snapshotHash(['items' => [['batch' => 'bat_1', 'qty' => '1']], 'amount' => 1200]));
    expect(fn () => SupervisorApprovalService::snapshotHash(['amount' => 1.2]))->toThrow(InvalidArgumentException::class);
});


it('limits managers to their assigned branch and keeps cashier tills private', function () {
    $this->context->run($this->alpha, function () {
        $access = app(\App\Services\TillAccessService::class);
        $other = Branch::create(['name' => 'Other']);
        expect($access->canView($this->alphaManager, $this->alphaBranch, $this->alphaCashier))->toBeTrue();
        expect($access->canView($this->alphaManager, $other, $this->alphaCashier))->toBeFalse();
        expect($access->canView($this->alphaOwner, $other, $this->alphaCashier))->toBeTrue();
        expect($access->canView($this->alphaCashier, $this->alphaBranch, $this->alphaCashier))->toBeTrue();
        expect($access->canView($this->alphaCashier, $this->alphaBranch, $this->alphaManager))->toBeFalse();
        expect($access->canManage($this->alphaOwner, $this->alphaBranch, $this->alphaCashier))->toBeFalse();
        expect($access->canManage($this->alphaCashier, $this->alphaBranch, $this->alphaCashier))->toBeTrue();
        expect($access->canManage($this->alphaCashier, $other, $this->alphaCashier))->toBeFalse();
        expect($access->canView($this->alphaStockkeeper, $this->alphaBranch, $this->alphaCashier))->toBeFalse();
        expect($access->canApproveVariance($this->alphaManager, $other))->toBeFalse();
        expect($access->canApproveVariance($this->alphaOwner, $other))->toBeTrue();
        expect(fn () => $access->canView($this->alphaOwner, $this->betaBranch, $this->alphaCashier))->toThrow(LogicException::class);
        expect(fn () => $access->canView($this->alphaOwner, $this->alphaBranch, $this->betaCashier))->toThrow(LogicException::class);
        $this->alphaManager->branch_id = null; $this->alphaManager->save();
        expect($access->canView($this->alphaManager, $this->alphaBranch, $this->alphaCashier))->toBeFalse();
        expect($access->canApproveVariance($this->alphaManager, $this->alphaBranch))->toBeFalse();
        $this->alphaCashier->is_active = false; $this->alphaCashier->save();
        expect($access->canManage($this->alphaCashier, $this->alphaBranch, $this->alphaCashier))->toBeFalse();
    });
});

it('rejects cross branch manager variance issuance and rechecks branch assignment at consumption', function () {
    $other = $this->context->run($this->alpha, fn () => Branch::create(['name' => 'Other']));
    $token = issueApproval($this, ['permission' => 'approve_variance']);
    $this->context->run($this->alpha, function () use ($other) {
        $this->alphaManager->branch_id = $other->id; $this->alphaManager->save();
    });
    $this->postJson(approvalUrl(), [...$this->input, 'permission' => 'approve_variance'])
        ->assertForbidden()->assertJsonPath('error.code', 'APPROVER_NOT_AUTHORIZED');
    expect(fn () => executeApproval($this, $token, ['permission' => 'approve_variance']))->toThrow(ApiException::class);
    $this->context->run($this->alpha, fn () => expect(SupervisorApproval::firstOrFail()->consumed_at)->toBeNull());
});

it('requires the authoritative target branch in a variance snapshot even for owners', function () {
    $other = $this->context->run($this->alpha, fn () => Branch::create(['name' => 'Other']));
    $this->snapshot['branch_id'] = $other->publicId();
    $this->input['payload_hash'] = SupervisorApprovalService::snapshotHash($this->snapshot);
    $token = issueApproval($this, ['permission' => 'approve_variance', 'phone' => '0700000001',
        'code' => app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP')]);
    expect(fn () => executeApproval($this, $token, ['permission' => 'approve_variance',
        'snapshot' => array_diff_key($this->snapshot, ['branch_id' => true])]))->toThrow(ApiException::class);
    expect(executeApproval($this, $token, ['permission' => 'approve_variance']))->toBe($this->alphaOwner->publicId());
});


it('rejects variance snapshots outside manager scope even when their approval digest matches', function () {
    $other = $this->context->run($this->alpha, fn () => Branch::create(['name' => 'Other']));
    foreach ([null, $other->publicId(), $this->betaBranch->publicId(), '1', ['invalid']] as $branchId) {
        $this->snapshot['branch_id'] = $branchId;
        $this->input['operation_id'] = (string) \Illuminate\Support\Str::uuid();
        $this->input['payload_hash'] = SupervisorApprovalService::snapshotHash($this->snapshot);
        $token = issueApproval($this, ['permission' => 'approve_variance']);
        expect(fn () => executeApproval($this, $token, ['permission' => 'approve_variance']))->toThrow(ApiException::class);
    }
    $this->context->run($this->alpha, fn () => expect(SupervisorApproval::whereNotNull('consumed_at')->count())->toBe(0));
});
