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
        \Tests\Support\GuardedTestDatabase::assertConnection();
        if(getenv('BIZISMART_REUSE_TEST_SCHEMA')==='1'&&!\Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated&&\Illuminate\Support\Facades\Schema::hasTable('migrations')){
            $required=array_map(fn($p)=>pathinfo($p,PATHINFO_FILENAME),glob(database_path('migrations/*.php')));
            $applied=\Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all();
            if(!array_diff($required,$applied)){
                \Tests\Support\GuardedTestDatabase::clear();
                \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated=true;
            }
            // Missing migrations still run Laravel's normal fresh schema verification.
        }
    }
}
