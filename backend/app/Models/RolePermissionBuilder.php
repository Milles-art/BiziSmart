<?php

namespace App\Models;

use LogicException;

class RolePermissionBuilder extends TenantBuilder
{
    public function insert(array $values)
    {
        if (! Role::whereKey($values['role_id'] ?? 0)->exists()) {
            throw new LogicException('A role permission must belong to the current business.');
        }
        return $this->toBase()->insert($values);
    }

    public function update(array $values)
    {
        foreach (array_keys($values) as $column) {
            if (preg_match('/(?:^|\.)role_id$/D', $column)) {
                throw new LogicException('A role permission cannot be reassigned.');
            }
        }
        return parent::update($values);
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('Role permissions do not have incrementable values.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('Role permissions do not have incrementable values.');
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        throw new LogicException('Role permissions do not have incrementable values.');
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        throw new LogicException('Role permissions do not have incrementable values.');
    }
}
