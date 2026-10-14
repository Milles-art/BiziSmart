<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {if(!Schema::hasColumn('platform_operations','input'))Schema::table('platform_operations',fn(Blueprint $t)=>$t->text('input')->nullable());}
    public function down():void {/* Evidence is retained on rollback; no operational history is deleted. */}
};
