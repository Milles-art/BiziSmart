<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\{Blueprint};
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('sale_items',fn(Blueprint $t)=>$t->unsignedBigInteger('cost_price')->nullable());
  foreach(['sales','sale_returns','sale_return_items','till_cash_entries'] as $table) Schema::table($table,fn(Blueprint $t)=>$t->index(['business_id','created_at'], $table.'_report_date'));
  Schema::create('expenses',function(Blueprint $t){$this->base($t);$t->unsignedBigInteger('actor_id');$t->string('category',80);$t->text('description');$t->date('incurred_on');$t->unsignedBigInteger('amount');$this->fk($t,'actor_id','users');$t->index(['business_id','incurred_on']);});
  Schema::create('expense_reversals',function(Blueprint $t){$this->base($t);$t->unsignedBigInteger('expense_id');$t->unsignedBigInteger('actor_id');$t->unsignedBigInteger('amount');$t->date('reversed_on');$t->text('reason');$this->fk($t,'expense_id','expenses');$this->fk($t,'actor_id','users');$t->index(['business_id','reversed_on']);});
  Schema::create('expense_payments',function(Blueprint $t){$this->base($t);$t->unsignedBigInteger('expense_id');$t->unsignedBigInteger('actor_id');$t->unsignedBigInteger('parent_payment_id')->nullable();$t->unsignedBigInteger('till_session_id')->nullable();$t->string('kind',16);$t->string('source',16);$t->unsignedBigInteger('amount');$t->date('paid_on');$t->text('reference')->nullable();$t->char('reference_hash',64)->nullable();$this->fk($t,'expense_id','expenses');$this->fk($t,'actor_id','users');$this->fk($t,'till_session_id','till_sessions');$this->fk($t,'parent_payment_id','expense_payments');$t->unique(['business_id','source','reference_hash']);$t->index(['business_id','paid_on']);});
  Schema::create('financial_operations',function(Blueprint $t){$this->base($t);$t->unsignedBigInteger('actor_id');$t->unsignedBigInteger('device_id');$t->uuid('operation_id');$t->string('type',40);$t->char('fingerprint',64);$t->longText('result');$t->unique(['business_id','operation_id']);$this->fk($t,'actor_id','users');$this->fk($t,'device_id','devices');});
  Schema::create('cash_drops',function(Blueprint $t){$this->base($t);$t->unsignedBigInteger('till_session_id');$t->unsignedBigInteger('branch_id');$t->unsignedBigInteger('cashier_id');$t->unsignedBigInteger('receiver_id');$t->unsignedBigInteger('acknowledged_by')->nullable();$t->unsignedBigInteger('reversed_by')->nullable();$t->unsignedBigInteger('amount');$t->text('reason');$t->string('status',20)->default('pending');$t->timestamp('acknowledged_at')->nullable();$t->timestamp('reversed_at')->nullable();$t->text('reversal_reason')->nullable();foreach(['till_session_id'=>'till_sessions','branch_id'=>'branches','cashier_id'=>'users','receiver_id'=>'users','acknowledged_by'=>'users','reversed_by'=>'users']as$c=>$table)$this->fk($t,$c,$table);$t->index(['business_id','till_session_id','status']);});
 }
 private function base(Blueprint $t):void{$t->id();$t->char('public_id',26)->unique();$t->unsignedBigInteger('business_id');$t->timestamps();$t->unique(['business_id','id']);$t->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();}
 private function fk(Blueprint $t,string $column,string $table):void{$t->foreign(['business_id',$column])->references(['business_id','id'])->on($table)->restrictOnDelete();}
 public function down():void{throw new LogicException('Operational journals cannot be rolled back destructively.');}
};
