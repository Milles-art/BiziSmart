<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_path', 255)->nullable();
            $table->string('image_mime', 32)->nullable();
            $table->unsignedInteger('image_bytes')->nullable();
            $table->unsignedSmallInteger('image_width')->nullable();
            $table->unsignedSmallInteger('image_height')->nullable();
        });
        Schema::create('product_image_cleanups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('path', 255);
            $table->timestamps();
            $table->unique(['business_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_cleanups');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['image_path', 'image_mime', 'image_bytes', 'image_width', 'image_height']));
    }
};
