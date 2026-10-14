<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('sales',function(Blueprint $t){
   $t->unsignedBigInteger('discount_approved_by')->nullable();$t->text('discount_reason')->nullable();
   $t->foreign(['business_id','discount_approved_by'],'sales_discount_approver_fk')->references(['business_id','id'])->on('users')->restrictOnDelete();
 });}
 public function down():void {Schema::table('sales',function(Blueprint $t){$t->dropForeign('sales_discount_approver_fk');$t->dropColumn(['discount_approved_by','discount_reason']);});}
};
