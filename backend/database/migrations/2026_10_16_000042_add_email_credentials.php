<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('email')->nullable();
            $table->char('email_lookup', 64)->nullable();
            $table->string('password_hash')->nullable();
            $table->unique(['business_id', 'email_lookup']);
        });
        Schema::create('email_credential_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('purpose', 16);
            $table->char('token_hash', 64)->unique();
            $table->text('email')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->foreign(['business_id', 'user_id'])->references(['business_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['business_id', 'user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_credential_tokens');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['business_id', 'email_lookup']);
            $table->dropColumn(['email', 'email_lookup', 'password_hash']);
        });
    }
};
