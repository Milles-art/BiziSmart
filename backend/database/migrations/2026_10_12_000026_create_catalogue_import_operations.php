<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalogue_import_operations', function (Blueprint $table) {
            $table->id(); $table->char('public_id', 26)->unique(); $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('device_id'); $table->uuid('operation_id');
            $table->char('request_fingerprint', 64); $table->json('result'); $table->timestamp('created_at');
            $table->unique(['business_id', 'operation_id']);
            $table->foreign(['business_id', 'user_id'])->references(['business_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['business_id', 'device_id'])->references(['business_id', 'id'])->on('devices')->restrictOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('catalogue_import_operations'); }
};
