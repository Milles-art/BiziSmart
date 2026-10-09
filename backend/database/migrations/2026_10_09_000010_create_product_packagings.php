<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_packagings', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('product_id');
            $table->char('family_id', 26);
            $table->unsignedInteger('version');
            $table->string('name', 160);
            $table->bigInteger('conversion_atoms');
            $table->unsignedBigInteger('selling_price');
            $table->string('barcode', 100)->nullable()->collation('utf8mb4_bin');
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'product_id', 'id'], 'packaging_owner_unique');
            $table->unique(['business_id', 'family_id', 'version'], 'packaging_version_unique');
            $table->foreign(['business_id', 'product_id'])->references(['business_id', 'id'])->on('products')->restrictOnDelete();
        });
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->unsignedBigInteger('packaging_id')->nullable();
            $table->foreign(['business_id', 'product_id', 'packaging_id'], 'barcode_packaging_owner_fk')
                ->references(['business_id', 'product_id', 'id'])->on('product_packagings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->dropForeign('barcode_packaging_owner_fk');
            $table->dropColumn('packaging_id');
        });
        Schema::dropIfExists('product_packagings');
    }
};
