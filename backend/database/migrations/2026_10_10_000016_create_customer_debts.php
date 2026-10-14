<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id(); $t->char('public_id', 26)->unique(); $t->unsignedBigInteger('business_id');
            $t->text('name'); $t->text('phone')->nullable(); $t->char('phone_lookup', 64)->nullable();
            $t->unsignedBigInteger('credit_limit')->default(0); $t->unsignedBigInteger('outstanding_balance')->default(0); $t->timestamps();
            $t->unique(['business_id','id']); $t->unique(['business_id','phone_lookup']);
            $t->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });
        Schema::table('sales', function (Blueprint $t) {
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->foreign(['business_id','customer_id'])->references(['business_id','id'])->on('customers')->restrictOnDelete();
            $t->unique(['business_id','customer_id','id']);
        });
        Schema::create('sale_debts', function (Blueprint $t) {
            $t->id(); $t->char('public_id',26)->unique(); $t->unsignedBigInteger('business_id');
            $t->unsignedBigInteger('customer_id'); $t->unsignedBigInteger('sale_id');
            $t->unsignedBigInteger('original_amount'); $t->unsignedBigInteger('remaining_amount'); $t->timestamps();
            $t->unique(['business_id','sale_id']); $t->unique(['business_id','customer_id','id']);
            $t->foreign(['business_id','customer_id','sale_id'])->references(['business_id','customer_id','id'])->on('sales')->restrictOnDelete();
        });
        Schema::create('customer_payments', function (Blueprint $t) {
            $t->id(); $t->char('public_id',26)->unique(); $t->unsignedBigInteger('business_id'); $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('received_by'); $t->unsignedBigInteger('device_id'); $t->unsignedBigInteger('till_session_id');
            $t->uuid('operation_id'); $t->char('request_fingerprint',64); $t->unsignedBigInteger('amount'); $t->string('method',16); $t->json('allocations'); $t->timestamp('created_at')->useCurrent();
            $t->unique(['business_id','operation_id']); $t->unique(['business_id','customer_id','id']);
            foreach (['customer_id'=>'customers','received_by'=>'users','device_id'=>'devices','till_session_id'=>'till_sessions'] as $field=>$table) {
                $t->foreign(['business_id',$field])->references(['business_id','id'])->on($table)->restrictOnDelete();
            }
        });
        Schema::create('debt_allocations', function (Blueprint $t) {
            $t->id(); $t->char('public_id',26)->unique(); $t->unsignedBigInteger('business_id'); $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('customer_payment_id'); $t->unsignedBigInteger('sale_debt_id'); $t->unsignedBigInteger('amount'); $t->timestamp('created_at')->useCurrent();
            $t->foreign(['business_id','customer_id','customer_payment_id'], 'debt_allocation_payment_fk')->references(['business_id','customer_id','id'])->on('customer_payments')->restrictOnDelete();
            $t->foreign(['business_id','customer_id','sale_debt_id'], 'debt_allocation_sale_debt_fk')->references(['business_id','customer_id','id'])->on('sale_debts')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('debt_allocations'); Schema::dropIfExists('customer_payments'); Schema::dropIfExists('sale_debts');
        Schema::table('sales', function (Blueprint $t) { $t->dropForeign(['business_id','customer_id']); $t->dropUnique(['business_id','customer_id','id']); $t->dropColumn('customer_id'); });
        Schema::dropIfExists('customers');
    }
};
