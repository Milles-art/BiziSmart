<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('admin_users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email')->unique();$t->string('password');$t->text('app_authentication_secret')->nullable();$t->boolean('is_active')->default(true);$t->rememberToken();$t->timestamps();});
        Schema::create('admin_totp_steps',function(Blueprint $t){$t->string('secret_hash',64)->primary();$t->unsignedBigInteger('last_step')->default(0);});
        Schema::create('subscriptions',function(Blueprint $t){$t->id();$t->ulid('public_id')->unique();$t->foreignId('business_id')->unique()->constrained()->restrictOnDelete();$t->foreignId('plan_id')->constrained()->restrictOnDelete();$t->dateTime('starts_at');$t->dateTime('ends_at');$t->string('status',16);$t->unsignedInteger('revision')->default(0);$t->timestamps();$t->unique(['business_id','id']);});
        Schema::create('subscription_payments',function(Blueprint $t){$t->id();$t->ulid('public_id')->unique();$t->foreignId('business_id')->constrained()->restrictOnDelete();$t->unsignedBigInteger('subscription_id');$t->unsignedBigInteger('amount');$t->string('method',16);$t->text('reference');$t->string('reference_hash',64);$t->dateTime('paid_at');$t->foreignId('recorded_by')->constrained('admin_users')->restrictOnDelete();$t->timestamp('created_at')->useCurrent();$t->unique(['business_id','reference_hash']);$t->foreign(['business_id','subscription_id'])->references(['business_id','id'])->on('subscriptions')->restrictOnDelete();});
        Schema::create('platform_operations',function(Blueprint $t){$t->id();$t->uuid('operation_id')->unique();$t->foreignId('admin_id')->constrained('admin_users')->restrictOnDelete();$t->string('action',32);$t->string('fingerprint',64);$t->text('input');$t->text('result');$t->timestamp('created_at')->useCurrent();});
        Schema::create('features',function(Blueprint $t){$t->id();$t->string('name');$t->string('scope');$t->text('value');$t->timestamps();$t->unique(['name','scope']);});
    }
    public function down():void {Schema::dropIfExists('features');Schema::dropIfExists('platform_operations');Schema::dropIfExists('subscription_payments');Schema::dropIfExists('subscriptions');Schema::dropIfExists('admin_totp_steps');Schema::dropIfExists('admin_users');}
};
