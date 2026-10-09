<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('barcode', 100)->nullable()->collation('utf8mb4_bin');
            $table->string('category', 100)->nullable();
            $table->string('quantity_type', 16);
            $table->string('unit', 32);
            $table->unsignedBigInteger('buying_price');
            $table->unsignedBigInteger('selling_price');
            $table->bigInteger('reorder_level_atoms')->default(0);
            $table->boolean('tracks_expiry')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'id']);
            $table->index(['business_id', 'name']);
        });
        // One registry reserves current and historical base codes. Packaging joins this registry in its own slice.
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('product_id');
            $table->string('barcode', 100)->collation('utf8mb4_bin');
            $table->timestamps();
            $table->unique(['business_id', 'barcode']);
            $table->foreign(['business_id', 'product_id'])->references(['business_id', 'id'])->on('products')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('products');
    }
};
