<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;

class RolePermission extends Pivot
{
    protected $table = 'role_has_permissions';
    public $timestamps = false;

    public function newEloquentBuilder($query)
    {
        return (new RolePermissionBuilder($query))->withGlobalScope('role_business', fn ($builder) => $builder->whereHas('role'));
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    private function assertRoleBusiness(): void
    {
        if (! Role::whereKey($this->role_id)->exists()
            || ($this->exists && (int) $this->getRawOriginal('role_id') !== (int) $this->role_id)) {
            throw new LogicException('A role permission must belong to the current business.');
        }
    }

    public function save(array $options = [])
    {
        $this->assertRoleBusiness();
        return parent::save($options);
    }

    public function delete()
    {
        $this->assertRoleBusiness();
        return parent::delete();
    }
}
