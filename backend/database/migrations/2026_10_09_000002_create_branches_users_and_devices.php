<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('address')->nullable();
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'id']);
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->text('name');
            $table->text('phone');
            $table->char('phone_lookup', 64);
            $table->string('pin_hash');
            $table->string('role_hint', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_pin')->default(true);
            $table->timestamp('locked_until')->nullable();
            $table->unsignedTinyInteger('failed_pin_attempts')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'phone_lookup']);
            $table->unique(['business_id', 'id']);
            $table->foreign(['business_id', 'branch_id'])->references(['business_id', 'id'])->on('branches')->restrictOnDelete();
        });
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('device_uid', 128);
            $table->text('name');
            $table->enum('status', ['pending', 'approved', 'blocked'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->text('last_ip')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'device_uid']);
            $table->unique(['business_id', 'id']);
            $table->foreign(['business_id', 'user_id'])->references(['business_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['business_id', 'approved_by'])->references(['business_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
        Schema::dropIfExists('users');
        Schema::dropIfExists('branches');
    }
};
