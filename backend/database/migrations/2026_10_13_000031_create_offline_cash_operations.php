<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('offline_leases', function(Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->restrictOnDelete(); $t->char('public_id',29)->unique();
            $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('device_id'); $t->unsignedBigInteger('till_session_id');
            $t->text('secret'); $t->longText('catalogue'); $t->unsignedTinyInteger('vat_rate');
            $t->timestamp('issued_at'); $t->timestamp('expires_at'); $t->timestamp('revoked_at')->nullable(); $t->timestamps();
            $t->unique(['business_id','id']); $t->index(['business_id','device_id','expires_at']);
            $t->foreign(['business_id','user_id'])->references(['business_id','id'])->on('users')->restrictOnDelete();
            $t->foreign(['business_id','device_id'])->references(['business_id','id'])->on('devices')->restrictOnDelete();
            $t->foreign(['business_id','till_session_id'])->references(['business_id','id'])->on('till_sessions')->restrictOnDelete();
        });
        Schema::create('offline_operations', function(Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->restrictOnDelete(); $t->char('public_id',29)->unique();
            $t->unsignedBigInteger('lease_id'); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('device_id'); $t->unsignedBigInteger('sale_id')->nullable();
            $t->uuid('operation_id'); $t->char('request_fingerprint',64); $t->longText('payload'); $t->longText('result')->nullable();
            $t->string('status',32); $t->json('conflicts'); $t->timestamp('captured_at')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable(); $t->text('resolution_reason')->nullable(); $t->timestamp('resolved_at')->nullable(); $t->timestamps();
            $t->unique(['business_id','id']); $t->unique(['business_id','operation_id']); $t->index(['business_id','status','created_at']);
            $t->foreign(['business_id','lease_id'])->references(['business_id','id'])->on('offline_leases')->restrictOnDelete();
            $t->foreign(['business_id','user_id'])->references(['business_id','id'])->on('users')->restrictOnDelete();
            $t->foreign(['business_id','device_id'])->references(['business_id','id'])->on('devices')->restrictOnDelete();
            $t->foreign(['business_id','sale_id'])->references(['business_id','id'])->on('sales')->restrictOnDelete();
            $t->foreign(['business_id','resolved_by'])->references(['business_id','id'])->on('users')->restrictOnDelete();
        });
        Schema::table('sales',function(Blueprint $t){$t->timestamp('occurred_at')->nullable()->index(); $t->boolean('is_offline')->default(false);});
    }
    public function down(): void { Schema::table('sales',fn(Blueprint $t)=>$t->dropColumn(['occurred_at','is_offline'])); Schema::dropIfExists('offline_operations'); Schema::dropIfExists('offline_leases'); }
};
