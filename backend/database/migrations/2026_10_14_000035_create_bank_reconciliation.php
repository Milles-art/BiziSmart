<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('bank_statement_lines',function(Blueprint $t){$t->id();$t->foreignId('business_id')->constrained();$t->ulid('public_id');$t->unsignedBigInteger('actor_id');$t->foreign(['business_id','actor_id'])->references(['business_id','id'])->on('users');$t->text('account');$t->date('booked_on');$t->string('direction',6);$t->unsignedBigInteger('amount');$t->text('reference');$t->char('identity_hash',64);$t->string('matched_type',16)->nullable();$t->unsignedBigInteger('matched_id')->nullable();$t->timestamp('created_at')->useCurrent();$t->unique(['business_id','public_id']);$t->unique(['business_id','identity_hash']);$t->unique(['business_id','matched_type','matched_id']);});
  Schema::create('supplier_payment_schedules',function(Blueprint $t){$t->id();$t->foreignId('business_id')->constrained();$t->ulid('public_id');$t->unsignedBigInteger('actor_id');$t->foreign(['business_id','actor_id'])->references(['business_id','id'])->on('users');$t->unsignedBigInteger('invoice_id');$t->foreign(['business_id','invoice_id'])->references(['business_id','id'])->on('supplier_invoices');$t->date('due_on');$t->unsignedBigInteger('amount');$t->string('status',16)->default('scheduled');$t->timestamps();$t->unique(['business_id','public_id']);});
 }
 public function down():void {Schema::dropIfExists('supplier_payment_schedules');Schema::dropIfExists('bank_statement_lines');}
};
