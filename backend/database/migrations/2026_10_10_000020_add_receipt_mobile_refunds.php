<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::table('sale_returns',function(Blueprint $t){$t->unsignedBigInteger('mobile_refund')->default(0);$t->text('mobile_refund_reference')->nullable();$t->char('mobile_refund_reference_hash',64)->nullable();$t->unique(['business_id','mobile_refund_reference_hash'],'return_mobile_reference_unique');});}
 public function down():void{Schema::table('sale_returns',function(Blueprint $t){$t->dropUnique('return_mobile_reference_unique');$t->dropColumn(['mobile_refund','mobile_refund_reference','mobile_refund_reference_hash']);});}
};
