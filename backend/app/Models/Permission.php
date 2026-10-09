<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends \Spatie\Permission\Models\Permission
{
    public function roles(): BelongsToMany
    {
        return parent::roles()->using(RolePermission::class);
    }

    public function users(): BelongsToMany
    {
        return parent::users()->using(PermissionMembership::class)
            ->withPivotValue('business_id', app(TenantContext::class)->requireId());
    }
}
