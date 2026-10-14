<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('mobile_payments', function(Blueprint $t) {
            $t->id(); $t->char('public_id',26)->unique(); $t->unsignedBigInteger('business_id');
            foreach(['actor_id','device_id','branch_id','till_session_id'] as $c) $t->unsignedBigInteger($c);
            $t->uuid('operation_id'); $t->char('request_fingerprint',64); $t->char('merchant_fingerprint',64);
            $t->text('request'); $t->unsignedBigInteger('amount'); $t->string('currency',3)->default('TZS');
            $t->string('status',24); $t->string('provider_transaction_id',150)->nullable()->unique();
            $t->string('provider_reference',150)->nullable()->unique(); $t->string('channel',150)->nullable();
            $t->string('last_error',64)->nullable(); $t->timestamp('next_check_at')->nullable();
            $t->timestamp('last_checked_at')->nullable(); $t->timestamp('confirmed_at')->nullable();
            $t->unsignedBigInteger('sale_id')->nullable()->unique(); $t->timestamps();
            $t->unique(['business_id','id']); $t->unique(['business_id','operation_id']); $t->index(['status','next_check_at']);
            foreach(['actor_id'=>'users','device_id'=>'devices','branch_id'=>'branches','till_session_id'=>'till_sessions','sale_id'=>'sales'] as $c=>$table)
                $t->foreign(['business_id',$c], 'mobile_'.$c.'_fk')->references(['business_id','id'])->on($table)->restrictOnDelete();
        });
        Schema::create('mobile_payment_notifications', function(Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('business_id'); $t->unsignedBigInteger('mobile_payment_id');
            $t->char('digest_hash',64); $t->timestamp('created_at')->useCurrent();
            $t->unique(['business_id','mobile_payment_id','digest_hash'],'mobile_notification_unique');
            $t->foreign(['business_id','mobile_payment_id'],'mobile_notification_fk')->references(['business_id','id'])->on('mobile_payments')->restrictOnDelete();
        });
        Schema::table('sale_payments', function(Blueprint $t) {
            $t->unsignedBigInteger('mobile_payment_id')->nullable()->unique();
            $t->foreign(['business_id','mobile_payment_id'],'sale_mobile_payment_fk')->references(['business_id','id'])->on('mobile_payments')->restrictOnDelete();
        });
    }
    public function down(): void {
        Schema::table('sale_payments',function(Blueprint $t){$t->dropForeign('sale_mobile_payment_fk');$t->dropColumn('mobile_payment_id');});
        Schema::dropIfExists('mobile_payment_notifications'); Schema::dropIfExists('mobile_payments');
    }
};
