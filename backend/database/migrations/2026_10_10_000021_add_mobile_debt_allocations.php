<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{
  if(!Schema::hasIndex('customer_payments','customer_payment_tenant_id_unique'))Schema::table('customer_payments',fn(Blueprint $t)=>$t->unique(['business_id','id'],'customer_payment_tenant_id_unique'));
  if(!Schema::hasColumn('mobile_payments','purpose'))Schema::table('mobile_payments',function(Blueprint $t){$t->string('purpose',16)->default('checkout');$t->unsignedBigInteger('customer_payment_id')->nullable()->unique();});
  if(!in_array('mobile_customer_payment_fk',array_column(Schema::getForeignKeys('mobile_payments'),'name'),true))Schema::table('mobile_payments',fn(Blueprint $t)=>$t->foreign(['business_id','customer_payment_id'],'mobile_customer_payment_fk')->references(['business_id','id'])->on('customer_payments')->restrictOnDelete());
  if(!Schema::hasColumn('customer_payments','mobile_payment_id'))Schema::table('customer_payments',function(Blueprint $t){$t->unsignedBigInteger('mobile_payment_id')->nullable()->unique();$t->foreign(['business_id','mobile_payment_id'],'customer_mobile_payment_fk')->references(['business_id','id'])->on('mobile_payments')->restrictOnDelete();});
 }
 public function down():void{
  Schema::table('customer_payments',function(Blueprint $t){$t->dropForeign('customer_mobile_payment_fk');$t->dropColumn('mobile_payment_id');});
  Schema::table('mobile_payments',function(Blueprint $t){$t->dropForeign('mobile_customer_payment_fk');$t->dropColumn(['purpose','customer_payment_id']);});
  Schema::table('customer_payments',fn(Blueprint $t)=>$t->dropUnique('customer_payment_tenant_id_unique'));
 }
};
