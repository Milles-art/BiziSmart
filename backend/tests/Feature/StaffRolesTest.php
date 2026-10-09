<?php

use App\Http\Responses\ApiResponse;
use App\Models\Business;
use App\Models\PermissionMembership;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\RoleMembership;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\StaffRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $this->roles = app(StaffRoleService::class);
    $plan = Plan::create(['code' => 'test', 'name' => 'Test', 'price_monthly' => 0, 'limits' => [], 'features' => []]);
    $this->alpha = Business::create(['name' => 'Alpha', 'subdomain' => 'alpha', 'plan_id' => $plan->id, 'owner_phone' => '0712345678']);
    $this->beta = Business::create(['name' => 'Beta', 'subdomain' => 'beta', 'plan_id' => $plan->id, 'owner_phone' => '0712345678']);
    foreach (['alpha', 'beta'] as $name) {
        $this->context->run($this->{$name}, fn () => $this->roles->provision());
    }
    Route::middleware(['api', 'auth:sanctum', 'permission:manage_products'])->get('/api/v1/role-test/products', fn () => ApiResponse::success(['allowed' => true]));
    Route::middleware(['api', 'auth:sanctum', 'permission:view_reports'])->get('/api/v1/role-test/reports', fn () => ApiResponse::success(['allowed' => true]));
});

afterEach(fn () => $this->context->clear());

function roleTestUser(string $role, string $phone = '0712345678'): User
{
    $user = User::create(['name' => 'Staff', 'phone' => $phone, 'pin_hash' => '1234', 'role_hint' => 'owner']);
    app(StaffRoleService::class)->assign($user, $role);
    return $user;
}

it('grants exactly the approved permissions to each role through Laravel Gate', function (string $role, array $allowed) {
    $this->context->run($this->alpha, function () use ($role, $allowed) {
        $user = roleTestUser($role);
        foreach (StaffRoleService::PERMISSIONS as $permission) {
            expect(Gate::forUser($user)->allows($permission))->toBe(in_array($permission, $allowed, true));
        }
        expect($user->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe(collect($allowed)->sort()->values()->all());
        expect($user->hasRole($role))->toBeTrue();
    });
})->with([
    'owner' => ['owner', StaffRoleService::PERMISSIONS],
    'manager' => ['manager', ['sell', 'refund', 'void', 'give_discount', 'manage_customers', 'manage_till', 'approve_variance']],
    'cashier' => ['cashier', ['sell', 'manage_customers', 'manage_till']],
    'stockkeeper' => ['stockkeeper', ['view_buying_price', 'manage_products', 'receive_stock', 'adjust_stock']],
]);

it('enforces permissions behind Sanctum without publishing test endpoints', function () {
    [$cashier, $stockkeeper, $manager, $owner] = $this->context->run($this->alpha, function () {
        return collect(['cashier', 'stockkeeper', 'manager', 'owner'])->map(fn ($role, $i) => roleTestUser($role, '071234567'.$i)->createToken('test')->plainTextToken)->all();
    });
    $this->withToken($cashier)->getJson('http://alpha.bizismart.test/api/v1/role-test/products')->assertForbidden()->assertJsonPath('error.code', 'PERMISSION_DENIED');
    $this->withToken($stockkeeper)->getJson('http://alpha.bizismart.test/api/v1/role-test/products')->assertOk()->assertJsonPath('data.allowed', true);
    $this->withToken($manager)->getJson('http://alpha.bizismart.test/api/v1/role-test/reports')->assertForbidden();
    $this->withToken($owner)->getJson('http://alpha.bizismart.test/api/v1/role-test/reports')->assertOk();
    $this->withToken($owner)->getJson('http://beta.bizismart.test/api/v1/role-test/reports')->assertUnauthorized();
});

it('does not grant permissions from the informational role hint or to disabled staff', function () {
    $this->context->run($this->alpha, function () {
        $user = User::create(['name' => 'Staff', 'phone' => '0712345678', 'pin_hash' => '1234', 'role_hint' => 'owner']);
        expect($user->can('manage_users'))->toBeFalse();
        $this->roles->assign($user, 'owner');
        expect($user->can('manage_users'))->toBeTrue();
        $user->is_active = false;
        $user->save();
        expect($user->can('manage_users'))->toBeFalse();
    });
});

it('keeps role membership and caches isolated across nested contexts', function () {
    $userA = $this->context->run($this->alpha, fn () => roleTestUser('cashier'));
    $userB = $this->context->run($this->beta, fn () => roleTestUser('owner'));
    $this->context->run($this->alpha, function () use ($userA, $userB) {
        expect($userA->can('view_reports'))->toBeFalse();
        $keyA = app(PermissionRegistrar::class)->cacheKey;
        $this->context->run($this->beta, function () use ($userA, $userB, $keyA) {
            expect($userB->can('view_reports'))->toBeTrue();
            expect(app(PermissionRegistrar::class)->cacheKey)->not->toBe($keyA);
            expect(fn () => $userA->can('sell'))->toThrow(LogicException::class);
            expect(fn () => $userA->getAllPermissions())->toThrow(LogicException::class);
            expect(fn () => $this->roles->assign($userA, 'owner'))->toThrow(LogicException::class);
        });
        expect(app(PermissionRegistrar::class)->cacheKey)->toBe($keyA);
        expect($userA->can('view_reports'))->toBeFalse();
    });
    expect(getPermissionsTeamId())->toBeNull();
    expect(Role::count())->toBe(0)->and(RoleMembership::count())->toBe(0)->and(PermissionMembership::count())->toBe(0);
});

it('cannot select a permission team independently of the resolved tenant', function () {
    expect(fn () => setPermissionsTeamId($this->alpha->id))->toThrow(LogicException::class);
    $this->context->run($this->alpha, function () {
        expect(getPermissionsTeamId())->toBe($this->alpha->id);
        expect(fn () => setPermissionsTeamId($this->beta->id))->toThrow(LogicException::class);
        setPermissionsTeamId($this->alpha->id);
        expect(getPermissionsTeamId())->toBe($this->alpha->id);
    });
});

it('rejects cross-business role and user attachments at the database boundary', function () {
    $roleB = $this->context->run($this->beta, fn () => Role::findByName('owner', 'web'));
    $userA = $this->context->run($this->alpha, fn () => roleTestUser('cashier'));
    $userB = $this->context->run($this->beta, fn () => roleTestUser('cashier'));
    $this->context->run($this->alpha, function () use ($roleB, $userA, $userB) {
        expect(fn () => $userA->assignRole($roleB))->toThrow(QueryException::class);
        expect(fn () => RoleMembership::create(['role_id' => Role::findByName('owner', 'web')->id, 'model_type' => User::class, 'model_id' => $userB->id]))->toThrow(QueryException::class);
        expect(fn () => $roleB->syncPermissions(['sell']))->toThrow(LogicException::class);
        $permission = Permission::findByName('sell', 'web');
        expect(fn () => $permission->roles()->attach($roleB->id))->toThrow(LogicException::class);
        expect(fn () => $permission->users()->attach($userB->id))->toThrow(QueryException::class);
        expect(fn () => RolePermission::query()->update(['role_id' => $roleB->id]))->toThrow(LogicException::class);
        expect(fn () => RolePermission::query()->increment('role_id'))->toThrow(LogicException::class);
        expect(fn () => RolePermission::query()->insert(['role_id' => $roleB->id, 'permission_id' => $permission->id]))->toThrow(LogicException::class);
    });
});

it('denies retained cached role and permission shortcuts after switching shops', function () {
    $user = $this->context->run($this->alpha, function () {
        $user = roleTestUser('owner');
        $user->givePermissionTo('sell');
        $user->load('roles.permissions', 'permissions');
        return $user;
    });
    $this->context->run($this->beta, function () use ($user) {
        foreach ([fn () => $user->hasRole('owner'), fn () => $user->hasAllRoles(['owner']),
            fn () => $user->hasExactRoles(['owner']), fn () => $user->hasDirectPermission('sell'),
            fn () => $user->getDirectPermissions(), fn () => $user->getPermissionsViaRoles(),
            fn () => $user->getRoleNames()] as $check) {
            expect($check)->toThrow(LogicException::class);
        }
    });
});

it('replaces a role and removes direct grants without retaining earlier authority', function () {
    $this->context->run($this->alpha, function () {
        $user = roleTestUser('owner');
        expect($user->can('view_reports'))->toBeTrue();
        $user->givePermissionTo('manage_users');
        $this->roles->assign($user, 'cashier');
        expect($user->can('view_reports'))->toBeFalse()->and($user->can('manage_users'))->toBeFalse()
            ->and($user->roles->pluck('name')->all())->toBe(['cashier']);
        expect(fn () => $this->roles->assign($user, 'superadmin'))->toThrow(InvalidArgumentException::class);
    });
});

it('provisions roles idempotently and denies provisioning without a shop', function () {
    expect(fn () => $this->roles->provision())->toThrow(LogicException::class);
    $this->context->run($this->alpha, function () {
        $this->roles->provision();
        expect(Role::count())->toBe(4);
        $this->roles->provision();
        expect(Role::count())->toBe(4);
        foreach (Role::all() as $role) {
            expect($role->permissions->pluck('name')->sort()->values()->all())
                ->toBe(collect(StaffRoleService::ROLES[$role->name])->sort()->values()->all());
        }
    });
});
