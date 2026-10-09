<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

class TenantBuilder extends Builder
{
    public function update(array $values)
    {
        app(TenantContext::class)->requireId();
        if ($this->changesBusinessId(array_keys($values))) {
            throw new LogicException('A tenant record cannot be reassigned.');
        }
        return parent::update($values);
    }

    public function insert(array $values)
    {
        throw new LogicException('Use tenant-aware model creation.');
    }

    public function insertOrIgnore(array $values)
    {
        throw new LogicException('Use tenant-aware model creation.');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException('Use tenant-aware model creation.');
    }

    public function insertGetId(array $values, $sequence = null)
    {
        if ((int) ($values['business_id'] ?? 0) !== app(TenantContext::class)->requireId()) {
            throw new LogicException('A tenant insert must use the current business.');
        }
        return $this->toBase()->insertGetId($values, $sequence);
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->assertIncrementAllowed($column, $extra);
        return parent::increment($column, $amount, $extra);
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->assertIncrementAllowed($column, $extra);
        return parent::decrement($column, $amount, $extra);
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        $this->assertWriteColumnsAllowed([...array_keys($columns), ...array_keys($extra)]);
        return parent::incrementEach($columns, $extra);
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        $this->assertWriteColumnsAllowed([...array_keys($columns), ...array_keys($extra)]);
        return parent::decrementEach($columns, $extra);
    }

    public function touch($column = null)
    {
        $this->assertWriteColumnsAllowed((array) $column);
        return parent::touch($column);
    }

    private function assertIncrementAllowed($column, array $extra): void
    {
        $this->assertWriteColumnsAllowed([$column, ...array_keys($extra)]);
    }

    private function assertWriteColumnsAllowed(array $columns): void
    {
        app(TenantContext::class)->requireId();
        if ($this->changesBusinessId($columns)) {
            throw new LogicException('A tenant record cannot be reassigned.');
        }
    }

    private function changesBusinessId(array $columns): bool
    {
        foreach ($columns as $column) {
            if (preg_match('/(?:^|\.)business_id$/D', (string) $column)) { return true; }
        }
        return false;
    }
}
