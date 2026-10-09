<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Business;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use App\Models\Permission;

class StaffRoleService
{
    public const PERMISSIONS = ['sell', 'refund', 'void', 'give_discount', 'view_buying_price', 'manage_products',
        'receive_stock', 'adjust_stock', 'manage_customers', 'manage_till', 'approve_variance', 'view_reports', 'manage_users', 'manage_settings'];

    public const ROLES = [
        'owner' => self::PERMISSIONS,
        'manager' => ['sell', 'refund', 'void', 'give_discount', 'manage_customers', 'manage_till', 'approve_variance'],
        'cashier' => ['sell', 'manage_customers', 'manage_till'],
        'stockkeeper' => ['view_buying_price', 'manage_products', 'receive_stock', 'adjust_stock'],
    ];

    public function provision(): void
    {
        app(TenantContext::class)->requireId();
        DB::transaction(function () {
            Business::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            foreach (self::PERMISSIONS as $permission) {
                Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            }
            foreach (self::ROLES as $name => $permissions) {
                $role = Role::findOrCreate($name, 'web');
                $before = $role->permissions->pluck('name')->sort()->values()->all();
                $role->syncPermissions($permissions);
                if ($before !== collect($permissions)->sort()->values()->all()) {
                    activity('staff')->performedOn($role)->event('role_permissions_updated')
                        ->withProperties(['old' => ['permissions' => $before], 'attributes' => ['permissions' => $permissions]])->log('Role permissions updated');
                }
            }
        });
    }

    public function assign(User $user, string $role): void
    {
        $user->assertCurrentBusiness();
        if (! array_key_exists($role, self::ROLES)) {
            throw new InvalidArgumentException('An approved staff role is required.');
        }
        DB::transaction(function () use ($user, $role) {
            $user->refreshForUpdate();
            $user->syncRoles(Role::findByName($role, 'web'));
            // Permissions are granted through the approved role only.
            $user->syncPermissions([]);
            $user->role_hint = $role;
            $user->save();
            \App\Models\SupervisorApproval::revokeFor($user);
        });
        $user->unsetRelation('roles')->unsetRelation('permissions');
    }
}
