<?php

use App\Models\Activity;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Device;
use App\Models\Plan;
use App\Models\User;
use App\Services\StaffRoleService;
use App\Tenancy\TenantContext;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $this->plan = Plan::create(['code' => 'staff-test', 'name' => 'Staff test', 'price_monthly' => 0, 'limits' => ['users' => 10], 'features' => []]);
    foreach (['alpha', 'beta'] as $label) {
        $business = Business::create(['name' => $label, 'subdomain' => $label, 'plan_id' => $this->plan->id, 'owner_phone' => '0700000001']);
        $this->{$label} = $business;
        $this->context->run($business, function () use ($label) {
            app(StaffRoleService::class)->provision();
            $branch = Branch::create(['name' => 'Main']);
            $owner = User::create(['name' => 'Private owner', 'phone' => '0700000001', 'pin_hash' => '1234', 'must_change_pin' => false]);
            app(StaffRoleService::class)->assign($owner, 'owner');
            $owner->totp_secret = 'JBSWY3DPEHPK3PXP';
            $owner->totp_confirmed_at = now();
            $owner->save();
            $staff = User::create(['name' => 'Private cashier', 'phone' => '0712345678', 'pin_hash' => '2345', 'must_change_pin' => false]);
            app(StaffRoleService::class)->assign($staff, 'cashier');
            $device = Device::create(['device_uid' => 'staff-test-device', 'name' => 'Test till', 'user_id' => $owner->id, 'status' => 'approved']);
            foreach (['Owner' => $owner, 'Staff' => $staff] as $kind => $user) {
                $token = $user->createToken('test', ['*'], now()->addDays(30));
                $token->accessToken->device_id = $device->id;
                $token->accessToken->save();
                $this->{'token'.$kind.ucfirst($label)} = $token->plainTextToken;
            }
            $this->{'owner'.ucfirst($label)} = $owner;
            $this->{'staff'.ucfirst($label)} = $staff;
            $this->{'branch'.ucfirst($label)} = $branch;
        });
    }
    $this->withToken($this->tokenOwnerAlpha);
});

afterEach(fn () => $this->context->clear());

function staffUrl(string $suffix = ''): string { return 'http://alpha.bizismart.test/api/v1/users'.$suffix; }

it('lists scoped staff with public IDs and creates hashed temporary PINs and approved roles', function () {
    $list = $this->getJson(staffUrl().'?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 1)->assertJsonMissingPath('data.users.0.pin_hash')->assertHeader('Cache-Control', 'no-store, private');
    expect($list->json('data.users.0.id'))->toStartWith('usr_');
    $response = $this->postJson(staffUrl(), ['name' => 'New staff', 'phone' => '+255711000003', 'pin' => '3456',
        'role' => 'manager', 'branch_id' => $this->branchAlpha->publicId(), 'business_id' => $this->beta->id]);
    $response->assertCreated()->assertJsonPath('data.role', 'manager')->assertJsonPath('data.must_change_pin', true)
        ->assertJsonPath('data.phone', '255711000003')->assertJsonMissingPath('data.pin')->assertJsonMissingPath('data.business_id');
    $this->context->run($this->alpha, function () {
        $user = User::forPhone('0711000003')->firstOrFail();
        expect(password_verify('3456', $user->pin_hash))->toBeTrue()->and($user->hasPermissionTo('view_reports'))->toBeFalse();
        expect($user->getRawOriginal('name'))->not->toBe('New staff')->and($user->getRawOriginal('phone'))->not->toBe('255711000003');
    });
});

it('rejects canonical duplicate phones, legacy roles and invalid or foreign branches', function () {
    $input = ['name' => 'Staff', 'phone' => '+255712345678', 'pin' => '3456', 'role' => 'cashier'];
    $this->postJson(staffUrl(), $input)->assertUnprocessable()->assertJsonPath('error.field', 'phone');
    $this->postJson(staffUrl(), [...$input, 'phone' => '0711000003', 'role' => 'Supervisor'])->assertUnprocessable();
    $this->postJson(staffUrl(), [...$input, 'phone' => '0711000003', 'branch_id' => $this->branchBeta->publicId()])->assertUnprocessable();
    $this->postJson(staffUrl(), [...$input, 'phone' => '0711000003', 'branch_id' => (string) $this->branchAlpha->id])->assertUnprocessable();
    $this->getJson(staffUrl().'?per_page=101')->assertUnprocessable();
});

it('changes staff role and profile, removes old grants, revokes sessions and avoids sensitive audit values', function () {
    $this->putJson(staffUrl('/'.$this->staffAlpha->publicId()), ['name' => 'Changed private name', 'phone' => '0711000003',
        'role' => 'stockkeeper', 'branch_id' => $this->branchAlpha->publicId()])->assertOk()->assertJsonPath('data.role', 'stockkeeper');
    $this->context->run($this->alpha, function () {
        $staff = $this->staffAlpha->fresh();
        expect($staff->tokens()->count())->toBe(0)->and($staff->hasPermissionTo('sell'))->toBeFalse()->and($staff->hasPermissionTo('receive_stock'))->toBeTrue();
        $event = Activity::where('event', 'staff_updated')->firstOrFail();
        expect($event->properties->get('changed_fields'))->toContain('role', 'phone', 'name');
        expect(json_encode($event->properties))->not->toContain('Changed private name', '0711000003', '2345');
    });
    $this->withToken($this->tokenStaffAlpha)->getJson('http://alpha.bizismart.test/api/v1/me')->assertUnauthorized();
});

it('deactivates staff without deleting history and allows owner reactivation', function () {
    $url = staffUrl('/'.$this->staffAlpha->publicId());
    $this->postJson($url.'/deactivate')->assertOk()->assertJsonPath('data.completed', true);
    $this->context->run($this->alpha, fn () => expect($this->staffAlpha->fresh()->is_active)->toBeFalse()->and($this->staffAlpha->tokens()->count())->toBe(0));
    $this->putJson($url, ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
    $this->putJson($url, ['pin' => '9999'])->assertUnprocessable();
});

it('protects the last active owner from deactivation and demotion', function () {
    $url = staffUrl('/'.$this->ownerAlpha->publicId());
    $this->postJson($url.'/deactivate')->assertStatus(409)->assertJsonPath('error.code', 'LAST_OWNER_REQUIRED');
    $this->putJson($url, ['role' => 'cashier'])->assertStatus(409);
    $this->context->run($this->alpha, fn () => expect($this->ownerAlpha->fresh()->is_active)->toBeTrue());
});

it('enforces active staff plan limits for both creation and reactivation', function () {
    $this->plan->limits = ['users' => 2];
    $this->plan->save();
    $this->postJson(staffUrl(), ['name' => 'Third', 'phone' => '0711000003', 'pin' => '3456', 'role' => 'cashier'])
        ->assertForbidden()->assertJsonPath('error.code', 'PLAN_LIMIT_REACHED');
    $url = staffUrl('/'.$this->staffAlpha->publicId());
    $this->postJson($url.'/deactivate')->assertOk();
    $this->postJson(staffUrl(), ['name' => 'Replacement', 'phone' => '0711000003', 'pin' => '3456', 'role' => 'cashier'])->assertCreated();
    $this->putJson($url, ['is_active' => true])->assertForbidden()->assertJsonPath('error.code', 'PLAN_LIMIT_REACHED');
});

it('rejects non-owners and foreign or numeric staff IDs without modifying them', function () {
    $this->withToken($this->tokenStaffAlpha)->getJson(staffUrl())->assertForbidden();
    $this->postJson(staffUrl('/'.$this->ownerAlpha->publicId().'/deactivate'))->assertForbidden();
    $this->withToken($this->tokenOwnerAlpha)->putJson(staffUrl('/'.$this->staffBeta->publicId()), ['name' => 'Foreign'])->assertNotFound();
    $this->postJson(staffUrl('/'.$this->staffAlpha->id.'/deactivate'))->assertNotFound();
    $this->withToken($this->tokenOwnerBeta)->getJson(staffUrl())->assertUnauthorized();
});
