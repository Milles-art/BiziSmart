<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{Schema::create('loss_alerts',function(Blueprint $table){$table->id();$table->char('public_id',26)->unique();$table->unsignedBigInteger('business_id');$table->unsignedBigInteger('user_id');$table->string('kind',32);$table->date('event_date');$table->json('evidence');$table->timestamp('acknowledged_at')->nullable();$table->unsignedBigInteger('acknowledged_by')->nullable();$table->timestamps();$table->unique(['business_id','user_id','kind','event_date'],'loss_alert_bucket_unique');$table->index(['business_id','event_date']);foreach(['user_id','acknowledged_by']as $column)$table->foreign(['business_id',$column])->references(['business_id','id'])->on('users')->restrictOnDelete();});}
    public function down():void{Schema::dropIfExists('loss_alerts');}
};
