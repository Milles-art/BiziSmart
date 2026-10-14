<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id(); $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('business_id'); $table->unsignedBigInteger('product_id');
            $table->string('batch_number', 100); $table->date('expiry_date')->nullable();
            $table->bigInteger('qty_remaining_atoms')->default(0); $table->unsignedBigInteger('cost_price');
            $table->timestamp('received_at'); $table->boolean('is_quarantined')->default(false); $table->boolean('is_recalled')->default(false);
            $table->uuid('operation_id'); $table->char('request_fingerprint', 64); $table->json('receipt'); $table->timestamps();
            $table->unique(['business_id', 'operation_id']);
            $table->unique(['business_id', 'product_id', 'id'], 'batch_owner_unique');
            $table->index(['business_id', 'product_id', 'expiry_date']);
            $table->foreign(['business_id', 'product_id'])->references(['business_id', 'id'])->on('products')->restrictOnDelete();
        });
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id(); $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('business_id'); $table->unsignedBigInteger('product_id'); $table->unsignedBigInteger('batch_id');
            $table->string('type', 32); $table->bigInteger('qty_change_atoms'); $table->bigInteger('qty_after_atoms');
            $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('device_id')->nullable();
            $table->uuid('operation_id'); $table->json('receipt'); $table->timestamp('created_at')->useCurrent();
            $table->unique(['business_id', 'operation_id']);
            $table->index(['business_id', 'batch_id', 'created_at']);
            $table->foreign(['business_id', 'product_id', 'batch_id'], 'movement_batch_owner_fk')->references(['business_id', 'product_id', 'id'])->on('product_batches')->restrictOnDelete();
            $table->foreign(['business_id', 'user_id'])->references(['business_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['business_id', 'device_id'])->references(['business_id', 'id'])->on('devices')->restrictOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('stock_movements'); Schema::dropIfExists('product_batches'); }
};
