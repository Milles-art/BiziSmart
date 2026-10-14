<?php
namespace Tests\Support;
use Illuminate\Support\Facades\{DB,Schema};
use LogicException;
final class GuardedTestDatabase {
    /** Fail closed before test migrations or fixture deletion, including an already-open mismatched connection. */
    public static function assertConnection():void {
        $configured=(string)config('database.connections.mysql.database');
        if(!app()->environment('testing')||config('database.default')!=='mysql'||!str_ends_with($configured,'_test'))throw new LogicException('Fixture cleanup requires a dedicated MySQL testing database.');
        $actual=DB::selectOne('SELECT DATABASE() AS current_database')->current_database;
        if($actual!==$configured||!str_ends_with((string)$actual,'_test'))throw new LogicException('The connected database must match the guarded testing schema.');
    }
    /** Clear committed race-test fixtures without rebuilding a schema already verified by migrate:fresh. */
    public static function clear():void {
        self::assertConnection();
        $tenantTables=array_fill_keys(array_map(fn($c)=>$c->TABLE_NAME,DB::select("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='business_id'")),true);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {DB::transaction(function()use($tenantTables){foreach(Schema::getTables() as $table){$name=$table['name'];if($name==='migrations')continue;if(!preg_match('/^[A-Za-z0-9_]+$/D',$name))throw new LogicException('Unexpected test table name.');DB::statement('DELETE FROM `'.$name.'`'.(isset($tenantTables[$name])?' WHERE business_id >= 0 OR business_id IS NULL':''));}});}finally{DB::statement('SET FOREIGN_KEY_CHECKS=1');}
    }
}
