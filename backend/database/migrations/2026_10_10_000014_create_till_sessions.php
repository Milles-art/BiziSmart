<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('till_sessions', function (Blueprint $t) {
            $t->id(); $t->char('public_id',26)->unique(); $t->unsignedBigInteger('business_id');
            $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('cashier_id');
            $t->string('status',16)->default('open'); $t->unsignedBigInteger('opening_float');
            $t->unsignedBigInteger('revision')->default(0); $t->timestamp('opened_at'); $t->timestamp('closed_at')->nullable();
            $t->unsignedBigInteger('closing_cash')->nullable(); $t->bigInteger('expected_cash')->nullable(); $t->bigInteger('variance')->nullable();
            $t->unsignedBigInteger('variance_limit')->nullable(); $t->unsignedBigInteger('approved_by')->nullable();
            $t->boolean('owner_review_required')->default(false); $t->timestamps();
            $t->unsignedBigInteger('open_cashier_id')->nullable()->storedAs("CASE WHEN status = 'open' THEN cashier_id ELSE NULL END");
            $t->unique(['business_id','branch_id','open_cashier_id'],'till_one_open_cashier');
            $t->unique(['business_id','id']); $t->index(['business_id','branch_id','status']);
            $t->foreign(['business_id','branch_id'])->references(['business_id','id'])->on('branches')->restrictOnDelete();
            foreach (['cashier_id','approved_by'] as $column) { $t->foreign(['business_id',$column])->references(['business_id','id'])->on('users')->restrictOnDelete(); }
        });
        Schema::create('till_cash_entries', function (Blueprint $t) {
            $t->id(); $t->char('public_id',26)->unique(); $t->unsignedBigInteger('business_id');
            $t->unsignedBigInteger('till_session_id'); $t->unsignedBigInteger('user_id');
            $t->string('type',32); $t->unsignedBigInteger('amount'); $t->text('reason')->nullable();
            $t->uuid('operation_id'); $t->char('request_fingerprint',64); $t->timestamp('created_at')->useCurrent();
            $t->unique(['business_id','operation_id']);
            $t->foreign(['business_id','till_session_id'])->references(['business_id','id'])->on('till_sessions')->restrictOnDelete();
            $t->foreign(['business_id','user_id'])->references(['business_id','id'])->on('users')->restrictOnDelete();
        });
        Schema::create('till_operations', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('business_id'); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('device_id');
            $t->uuid('operation_id'); $t->string('type',16); $t->char('request_fingerprint',64); $t->longText('result'); $t->timestamp('created_at')->useCurrent();
            $t->unique(['business_id','operation_id']);
            $t->foreign(['business_id','user_id'])->references(['business_id','id'])->on('users')->restrictOnDelete();
            $t->foreign(['business_id','device_id'])->references(['business_id','id'])->on('devices')->restrictOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('till_operations'); Schema::dropIfExists('till_cash_entries'); Schema::dropIfExists('till_sessions'); }
};
