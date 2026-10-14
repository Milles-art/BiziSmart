<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('product_price_histories')) { Schema::create('product_price_histories', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('packaging_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('product_name', 160);
            $table->string('packaging_name', 160)->nullable();
            $table->string('unit', 32);
            $table->string('base_qty', 32);
            $table->unsignedInteger('packaging_version')->nullable();
            $table->unsignedBigInteger('old_price')->nullable();
            $table->unsignedBigInteger('new_price');
            $table->timestamp('created_at');
        }); }
        // MySQL DDL commits each step: resume safely after interrupted additive setup.
        $foreign = array_column(Schema::getForeignKeys('product_price_histories'), 'name');
        if (! in_array('product_price_histories_business_id_product_id_foreign', $foreign, true)) {
            Schema::table('product_price_histories', fn (Blueprint $table) => $table->foreign(['business_id', 'product_id'])->references(['business_id', 'id'])->on('products')->restrictOnDelete());
        }
        if (! in_array('price_history_packaging_owner_fk', $foreign, true)) {
            Schema::table('product_price_histories', fn (Blueprint $table) => $table->foreign(['business_id', 'product_id', 'packaging_id'], 'price_history_packaging_owner_fk')->references(['business_id', 'product_id', 'id'])->on('product_packagings')->restrictOnDelete());
        }
        if (! in_array('product_price_histories_business_id_user_id_foreign', $foreign, true)) {
            Schema::table('product_price_histories', fn (Blueprint $table) => $table->foreign(['business_id', 'user_id'])->references(['business_id', 'id'])->on('users')->restrictOnDelete());
        }
        if (! Schema::hasIndex('product_price_histories', 'product_price_history_lookup')) {
            Schema::table('product_price_histories', fn (Blueprint $table) => $table->index(['business_id', 'product_id', 'id'], 'product_price_history_lookup'));
        }
    }

    public function down(): void { Schema::dropIfExists('product_price_histories'); }
};
