<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;

trait RefreshTenantDatabase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing') || config('database.default') !== 'mysql'
            || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
            throw new LogicException('Database tests require a dedicated MySQL *_test database.');
        }
    }
}
