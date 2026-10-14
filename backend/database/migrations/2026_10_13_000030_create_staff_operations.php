<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    private function base(Blueprint $t):void {$t->id();$t->char('public_id',26)->unique();$t->unsignedBigInteger('business_id');$t->unique(['business_id','id']);}
    private function link(Blueprint $t,string $column,string $table,bool $nullable=false):void {$field=$t->unsignedBigInteger($column);if($nullable)$field->nullable();$t->foreign(['business_id',$column])->references(['business_id','id'])->on($table)->restrictOnDelete();}
    public function up():void {
        Schema::create('attendance_sessions',function(Blueprint $t){$this->base($t);$this->link($t,'user_id','users');$this->link($t,'device_id','devices');$this->link($t,'active_user_id','users',true);$t->timestamp('started_at');$t->timestamp('ended_at')->nullable();$t->unsignedInteger('revision')->default(0);$t->timestamp('created_at')->useCurrent();$t->unique(['business_id','active_user_id']);});
        Schema::create('attendance_corrections',function(Blueprint $t){$this->base($t);$this->link($t,'attendance_id','attendance_sessions');$this->link($t,'actor_id','users');$t->text('original_snapshot');$t->timestamp('corrected_started_at');$t->timestamp('corrected_ended_at')->nullable();$t->text('reason');$t->timestamp('created_at')->useCurrent();});
        Schema::create('notification_preferences',function(Blueprint $t){$this->base($t);$this->link($t,'user_id','users');$t->boolean('in_app')->default(true);$t->boolean('whatsapp')->default(false);$t->boolean('sms')->default(false);$t->unsignedInteger('revision')->default(0);$t->timestamps();$t->unique(['business_id','user_id']);});
        Schema::create('staff_notifications',function(Blueprint $t){$this->base($t);$this->link($t,'user_id','users');$this->link($t,'branch_id','branches',true);$this->link($t,'till_session_id','till_sessions',true);$t->char('event_key',64);$t->string('permission',40);$t->string('type',32);$t->text('payload');$t->timestamp('read_at')->nullable();$t->timestamp('created_at')->useCurrent();$t->unique(['business_id','user_id','event_key']);});
        Schema::create('staff_operations',function(Blueprint $t){$this->base($t);$this->link($t,'actor_id','users');$this->link($t,'device_id','devices');$t->uuid('operation_id');$t->char('request_fingerprint',64);$t->string('action',32);$t->text('result');$t->timestamp('created_at')->useCurrent();$t->unique(['business_id','operation_id']);});
    }
    public function down():void {foreach(['staff_operations','staff_notifications','notification_preferences','attendance_corrections','attendance_sessions'] as $table)Schema::dropIfExists($table);}
};
