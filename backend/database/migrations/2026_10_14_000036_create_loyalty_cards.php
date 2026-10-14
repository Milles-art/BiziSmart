<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void { Schema::create('loyalty_cards',function(Blueprint $t){$t->id();$t->foreignId('business_id')->constrained()->restrictOnDelete();$t->ulid('public_id')->unique();$t->foreignId('customer_id')->constrained()->restrictOnDelete();$t->foreignId('user_id')->constrained()->restrictOnDelete();$t->foreignId('device_id')->constrained()->restrictOnDelete();$t->uuid('operation_id');$t->string('request_fingerprint',64);$t->string('token_hash',64);$t->text('token');$t->boolean('active')->default(true);$t->timestamps();$t->unique(['business_id','operation_id']);$t->unique(['business_id','token_hash']);$t->index(['business_id','customer_id','active']);}); }
 public function down():void { Schema::dropIfExists('loyalty_cards'); }
};
