<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('held_carts', function (Blueprint $t) {
            $t->id(); $t->char('public_id',26)->unique(); $t->unsignedBigInteger('business_id');
            $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('actor_id'); $t->unsignedBigInteger('device_id');
            $t->text('draft'); $t->string('status',16)->default('held'); $t->timestamp('expires_at'); $t->timestamps();
            $t->unique(['business_id','id']); $t->index(['business_id','branch_id','status','expires_at']);
            foreach (['branch_id'=>'branches','actor_id'=>'users','device_id'=>'devices'] as $column=>$table) {
                $t->foreign(['business_id',$column])->references(['business_id','id'])->on($table)->restrictOnDelete();
            }
        });
        Schema::create('held_cart_operations', function (Blueprint $t) {
            $t->id(); $t->char('public_id',26)->unique(); $t->unsignedBigInteger('business_id');
            $t->unsignedBigInteger('held_cart_id'); $t->unsignedBigInteger('actor_id'); $t->unsignedBigInteger('device_id');
            $t->uuid('operation_id'); $t->char('request_fingerprint',64); $t->string('action',16); $t->text('result'); $t->timestamp('created_at')->useCurrent();
            $t->unique(['business_id','operation_id']);
            foreach (['held_cart_id'=>'held_carts','actor_id'=>'users','device_id'=>'devices'] as $column=>$table) {
                $t->foreign(['business_id',$column])->references(['business_id','id'])->on($table)->restrictOnDelete();
            }
        });
    }
    public function down(): void { Schema::dropIfExists('held_cart_operations'); Schema::dropIfExists('held_carts'); }
};
