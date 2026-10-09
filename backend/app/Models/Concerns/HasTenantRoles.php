<?php

namespace App\Models\Concerns;

use App\Models\PermissionMembership;
use App\Models\RoleMembership;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Traits\HasRoles;

trait HasTenantRoles
{
    use HasRoles {
        roles as private packageRoles;
        permissions as private packagePermissions;
        hasPermissionTo as private packageHasPermissionTo;
        hasRole as private packageHasRole;
        getAllPermissions as private packageGetAllPermissions;
        hasDirectPermission as private packageHasDirectPermission;
        getPermissionsViaRoles as private packageGetPermissionsViaRoles;
        getDirectPermissions as private packageGetDirectPermissions;
        getRoleNames as private packageGetRoleNames;
        hasAllRoles as private packageHasAllRoles;
        hasExactRoles as private packageHasExactRoles;
    }

    protected function getDefaultGuardName(): string { return 'web'; }

    public function roles(): BelongsToMany
    {
        if ($this->exists) { $this->assertCurrentBusiness(); }
        return $this->packageRoles()->using(RoleMembership::class);
    }

    public function permissions(): BelongsToMany
    {
        if ($this->exists) { $this->assertCurrentBusiness(); }
        return $this->packagePermissions()->using(PermissionMembership::class);
    }

    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $this->assertCurrentBusiness();
        return $this->is_active && ! $this->trashed() && $this->packageHasPermissionTo($permission, $guardName);
    }

    public function hasRole($roles, ?string $guard = null): bool
    {
        $this->assertCurrentBusiness();
        return $this->packageHasRole($roles, $guard);
    }

    public function getAllPermissions(): \Illuminate\Support\Collection
    {
        $this->assertCurrentBusiness();
        return $this->packageGetAllPermissions();
    }

    public function hasDirectPermission($permission): bool
    {
        $this->assertCurrentBusiness();
        return $this->is_active && ! $this->trashed() && $this->packageHasDirectPermission($permission);
    }

    public function getPermissionsViaRoles(): \Illuminate\Support\Collection
    {
        $this->assertCurrentBusiness();
        return $this->packageGetPermissionsViaRoles();
    }

    public function getDirectPermissions(): \Illuminate\Support\Collection
    {
        $this->assertCurrentBusiness();
        return $this->packageGetDirectPermissions();
    }

    public function getRoleNames(): \Illuminate\Support\Collection
    {
        $this->assertCurrentBusiness();
        return $this->packageGetRoleNames();
    }

    public function hasAllRoles($roles, ?string $guard = null): bool
    {
        $this->assertCurrentBusiness();
        return $this->packageHasAllRoles($roles, $guard);
    }

    public function hasExactRoles($roles, ?string $guard = null): bool
    {
        $this->assertCurrentBusiness();
        return $this->packageHasExactRoles($roles, $guard);
    }
}
