<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('stock_movements', function (Blueprint $table) {
        $table->char('request_fingerprint', 64)->nullable(); $table->text('reason')->nullable(); $table->unsignedBigInteger('approved_by')->nullable();
        $table->foreign(['business_id', 'approved_by'], 'movement_approver_owner_fk')->references(['business_id', 'id'])->on('users')->restrictOnDelete();
    }); }
    public function down(): void { Schema::table('stock_movements', function (Blueprint $table) { $table->dropForeign('movement_approver_owner_fk'); $table->dropColumn(['request_fingerprint', 'reason', 'approved_by']); }); }
};
