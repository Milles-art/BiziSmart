<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->unsignedBigInteger('price_monthly');
            $table->json('limits');
            $table->json('features');
            $table->timestamps();
        });
        Schema::create('businesses', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('subdomain', 63)->unique();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->text('owner_phone');
            $table->text('tin')->nullable();
            $table->text('vrn')->nullable();
            $table->unsignedTinyInteger('vat_rate')->default(18);
            $table->text('address')->nullable();
            $table->text('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
        Schema::dropIfExists('plans');
    }
};
