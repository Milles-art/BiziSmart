<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('manual_mobile_refunds',function(Blueprint $t){
   $t->id();$t->char('public_id',26)->unique();$t->unsignedBigInteger('business_id');
   foreach(['mobile_payment_id','user_id','device_id','approved_by'] as $c)$t->unsignedBigInteger($c);
   $t->uuid('operation_id');$t->char('request_fingerprint',64);$t->char('external_reference_hash',64);
   $t->text('external_reference');$t->text('reason');$t->unsignedBigInteger('amount');$t->timestamp('created_at')->useCurrent();
   $t->unique(['business_id','operation_id']);$t->unique(['business_id','mobile_payment_id']);$t->unique(['business_id','external_reference_hash']);
   foreach(['mobile_payment_id'=>'mobile_payments','user_id'=>'users','device_id'=>'devices','approved_by'=>'users'] as $c=>$table)
    $t->foreign(['business_id',$c],'manual_mobile_'.$c.'_fk')->references(['business_id','id'])->on($table)->restrictOnDelete();
  });
 }
 public function down(): void{Schema::dropIfExists('manual_mobile_refunds');}
};
