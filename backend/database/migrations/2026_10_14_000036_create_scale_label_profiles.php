<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('scale_label_profiles',function(Blueprint $t){$t->id();$t->foreignId('business_id')->constrained()->restrictOnDelete();$t->ulid('public_id')->unique();$t->foreignId('user_id')->constrained()->restrictOnDelete();$t->foreignId('device_id')->constrained()->restrictOnDelete();$t->uuid('operation_id');$t->string('request_fingerprint',64);$t->json('profile')->nullable();$t->timestamps();$t->unique(['business_id','operation_id']);});}
 public function down():void {Schema::dropIfExists('scale_label_profiles');}
};
