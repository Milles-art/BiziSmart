<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $t) {
            $t->id(); $t->char('public_id', 26)->unique(); $t->unsignedBigInteger('business_id');
            $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('cashier_id'); $t->unsignedBigInteger('device_id'); $t->unsignedBigInteger('till_session_id');
            $t->uuid('operation_id'); $t->char('request_fingerprint', 64); $t->string('receipt_number', 40); $t->string('status', 32)->default('completed');
            foreach (['subtotal', 'discount', 'total', 'vat_amount', 'cash_tendered', 'change_given'] as $c) { $t->unsignedBigInteger($c); }
            $t->unsignedInteger('vat_rate'); $t->json('lines'); $t->timestamps();
            $t->unique(['business_id', 'id']); $t->unique(['business_id', 'operation_id']); $t->unique(['business_id', 'receipt_number']);
            foreach (['branch_id'=>'branches', 'cashier_id'=>'users', 'device_id'=>'devices', 'till_session_id'=>'till_sessions'] as $c=>$table) {
                $t->foreign(['business_id', $c])->references(['business_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        Schema::create('sale_items', function (Blueprint $t) {
            $t->id(); $t->char('public_id', 26)->unique(); $t->unsignedBigInteger('business_id'); $t->unsignedBigInteger('sale_id');
            $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('batch_id'); $t->unsignedInteger('line_index'); $t->bigInteger('qty_atoms');
            $t->unsignedBigInteger('unit_price'); $t->timestamp('created_at')->useCurrent();
            $t->foreign(['business_id','sale_id'])->references(['business_id','id'])->on('sales')->restrictOnDelete();
            $t->foreign(['business_id','product_id','batch_id'])->references(['business_id','product_id','id'])->on('product_batches')->restrictOnDelete();
        });
        Schema::create('sale_payments', function (Blueprint $t) {
            $t->id(); $t->char('public_id', 26)->unique(); $t->unsignedBigInteger('business_id'); $t->unsignedBigInteger('sale_id');
            $t->string('method', 16); $t->unsignedBigInteger('amount'); $t->timestamp('created_at')->useCurrent();
            $t->foreign(['business_id','sale_id'])->references(['business_id','id'])->on('sales')->restrictOnDelete();
        });
        Schema::table('businesses', fn (Blueprint $t) => $t->unsignedBigInteger('sale_sequence')->default(0));
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->unsignedBigInteger('sale_id')->nullable();
            $t->foreign(['business_id','sale_id'])->references(['business_id','id'])->on('sales')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $t) { $t->dropForeign(['business_id','sale_id']); $t->dropColumn('sale_id'); });
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('sale_sequence'));
        Schema::dropIfExists('sale_payments'); Schema::dropIfExists('sale_items'); Schema::dropIfExists('sales');
    }
};
