<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('current_name', 160)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'id']);
            $table->unique(['business_id', 'current_name']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable();
            $table->foreign(['business_id', 'department_id'], 'product_department_owner_fk')
                ->references(['business_id', 'id'])->on('departments')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign('product_department_owner_fk');
            $table->dropColumn('department_id');
        });
        Schema::dropIfExists('departments');
    }
};
