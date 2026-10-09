<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->unique(['business_id', 'id'], 'tokens_business_id_unique'));
        Schema::create('supervisor_approvals', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('requester_id');
            $table->unsignedBigInteger('approver_id');
            $table->unsignedBigInteger('requester_token_id');
            $table->unsignedBigInteger('device_id');
            $table->string('permission', 32);
            $table->uuid('operation_id');
            $table->char('payload_hash', 64);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->foreign(['business_id', 'requester_id'])->references(['business_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign(['business_id', 'approver_id'])->references(['business_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign(['business_id', 'device_id'])->references(['business_id', 'id'])->on('devices')->cascadeOnDelete();
            $table->foreign(['business_id', 'requester_token_id'])->references(['business_id', 'id'])->on('personal_access_tokens')->cascadeOnDelete();
            $table->index(['business_id', 'requester_token_id', 'operation_id'], 'approvals_request_operation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_approvals');
        Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->dropUnique('tokens_business_id_unique'));
    }
};
