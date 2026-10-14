<?php
namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Schema};
use LogicException;

/** Provider tests need committed fixtures: outbound requests must not run in a DB transaction. */
trait RefreshPaymentDatabase {
    use RefreshDatabase;
    protected function beforeRefreshingDatabase(): void {
        $this->guardPaymentDatabase();
        // Reuse the migrated dedicated schema; this trait deletes fixtures before/after every test.
        // A missing migration still invokes Laravel's normal guarded schema refresh.
        if(Schema::hasTable('migrations')) {
            $required=array_map(fn($p)=>pathinfo($p,PATHINFO_FILENAME),glob(database_path('migrations/*.php')));
            $applied=DB::table('migrations')->pluck('migration')->all();
            if(!array_diff($required,$applied)) \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated=true;
        }
    }
    public function beginDatabaseTransaction(): void {
        $this->clearPaymentDatabase();
        $this->beforeApplicationDestroyed(fn()=> $this->clearPaymentDatabase());
    }
    private function guardPaymentDatabase(): void {
        if(!app()->environment('testing') || config('database.default')!=='mysql'
            || !str_ends_with((string)config('database.connections.mysql.database'),'_test'))
            throw new LogicException('Payment tests require a dedicated MySQL *_test database.');
        \Tests\Support\GuardedTestDatabase::assertConnection();
    }
    private function clearPaymentDatabase(): void {
        $this->guardPaymentDatabase();
        $tenantTables=array_fill_keys(array_map(fn($column)=>$column->TABLE_NAME,
            DB::select("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='business_id'")),true);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {DB::transaction(function()use($tenantTables){foreach(Schema::getTables() as $table){$name=$table['name'];
            if($name==='migrations')continue;
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$name))throw new LogicException('Unexpected table name.');
            DB::statement('DELETE FROM `'.$name.'`'.(isset($tenantTables[$name]) ? ' WHERE business_id >= 0 OR business_id IS NULL' : ''));
        }});}finally{DB::statement('SET FOREIGN_KEY_CHECKS=1');}
    }
}
