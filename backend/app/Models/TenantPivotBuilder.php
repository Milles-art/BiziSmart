<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use LogicException;

class TenantPivotBuilder extends TenantBuilder
{
    public function insert(array $values)
    {
        // Non-incrementing Eloquent pivots use insert rather than insertGetId.
        if ((int) ($values['business_id'] ?? 0) !== app(TenantContext::class)->requireId()
            || ($values['model_type'] ?? null) !== User::class) {
            throw new LogicException('A staff membership must use the current business.');
        }
        return $this->toBase()->insert($values);
    }
}
