<?php

use App\Models\Business;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reject invalid/duplicate canonical phones before performing any non-transactional MySQL DDL.
        Business::query()->each(function (Business $business) {
            $business->is_active = true; // Migration-only in-memory context; never persisted.
            app(TenantContext::class)->run($business, function () {
                $seen = [];
                foreach (User::withTrashed()->cursor() as $user) {
                    $lookup = User::phoneLookup($user->phone);
                    if (isset($seen[$lookup])) {
                        throw new RuntimeException('Resolve duplicate canonical staff phones before migrating.');
                    }
                    $seen[$lookup] = true;
                }
            });
        });
        Schema::table('users', function (Blueprint $table) {
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->unsignedBigInteger('totp_last_step')->nullable();
        });
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->nullable();
            $table->foreign(['business_id', 'device_id'])->references(['business_id', 'id'])->on('devices')->restrictOnDelete();
        });
        Schema::create('auth_challenges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('device_id');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->boolean('two_factor_verified')->default(false);
            $table->text('setup_secret')->nullable();
            $table->timestamps();
            $table->foreign(['business_id', 'user_id'])->references(['business_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign(['business_id', 'device_id'])->references(['business_id', 'id'])->on('devices')->cascadeOnDelete();
        });
        // Existing rows are read through the tenant boundary, including inactive shops.
        Business::query()->each(function (Business $business) {
            if (! $business->is_active) {
                $business->is_active = true; // In-memory migration context only; never saved.
            }
            app(TenantContext::class)->run($business, function () {
                User::withTrashed()->each(function (User $user) {
                    $user->phone = \App\Support\StaffPhone::normalize($user->phone);
                    $user->save();
                });
            });
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_challenges');
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['business_id', 'device_id']);
            $table->dropColumn('device_id');
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['totp_secret', 'totp_confirmed_at', 'totp_last_step']));
    }
};
