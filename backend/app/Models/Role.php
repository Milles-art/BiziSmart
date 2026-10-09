<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends \Spatie\Permission\Models\Role
{
    use BelongsToBusiness, \Spatie\Activitylog\Traits\LogsActivity;

    public function getActivitylogOptions(): \Spatie\Activitylog\LogOptions
    {
        return \Spatie\Activitylog\LogOptions::defaults()->logOnly(['name', 'guard_name'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    protected $hidden = ['id', 'business_id'];

    public function permissions(): BelongsToMany
    {
        if ($this->exists) { $this->assertCurrentBusiness(); }
        return parent::permissions()->using(RolePermission::class);
    }

    public function users(): BelongsToMany
    {
        if ($this->exists) { $this->assertCurrentBusiness(); }
        return parent::users()->using(RoleMembership::class)
            ->wherePivot('business_id', app(\App\Tenancy\TenantContext::class)->requireId());
    }

    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        $this->assertCurrentBusiness();
        return parent::hasPermissionTo($permission, $guardName);
    }
}
