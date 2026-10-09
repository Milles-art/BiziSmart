<?php

namespace App\Tenancy;

use LogicException;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

class PermissionTeamResolver implements PermissionsTeamResolver
{
    public function getPermissionsTeamId(): int|string|null
    {
        return app(TenantContext::class)->id();
    }

    public function setPermissionsTeamId($id): void
    {
        // Only tenant resolution may select a business; the package cannot override it.
        if ($id !== app(TenantContext::class)->id()) {
            throw new LogicException('Permission teams are selected by the tenant context.');
        }
    }
}
