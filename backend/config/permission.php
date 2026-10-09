<?php

return [
    'models' => ['permission' => App\Models\Permission::class, 'role' => App\Models\Role::class],
    'table_names' => ['roles' => 'roles', 'permissions' => 'permissions', 'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles', 'role_has_permissions' => 'role_has_permissions'],
    'column_names' => ['role_pivot_key' => null, 'permission_pivot_key' => null, 'model_morph_key' => 'model_id', 'team_foreign_key' => 'business_id'],
    'teams' => true,
    'team_resolver' => App\Tenancy\PermissionTeamResolver::class,
    'register_permission_check_method' => true,
    'register_octane_reset_listener' => false,
    'events_enabled' => false,
    'use_passport_client_credentials' => false,
    'display_permission_in_exception' => false,
    'display_role_in_exception' => false,
    'enable_wildcard_permission' => false,
    // TenantContext replaces this key before any tenant work, including nested jobs.
    'cache' => ['expiration_time' => DateInterval::createFromDateString('24 hours'), 'key' => 'business:none:permissions', 'store' => 'default'],
];
