<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Permissions are a global vocabulary; roles and staff assignments belong to a shop.
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('guard_name', 32);
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 32);
            $table->string('guard_name', 32);
            $table->timestamps();
            $table->unique(['business_id', 'name', 'guard_name']);
            $table->unique(['business_id', 'id']);
        });
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });
        foreach (['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $name => $key) {
            Schema::create($name, function (Blueprint $table) use ($key) {
                $table->unsignedBigInteger('business_id');
                $table->unsignedBigInteger($key);
                $table->string('model_type', 128);
                $table->unsignedBigInteger('model_id');
                $table->primary(['business_id', $key, 'model_id', 'model_type']);
                $table->index(['model_id', 'model_type']);
                $table->foreign(['business_id', 'model_id'])->references(['business_id', 'id'])->on('users')->cascadeOnDelete();
                if ($key === 'role_id') {
                    $table->foreign(['business_id', 'role_id'])->references(['business_id', 'id'])->on('roles')->cascadeOnDelete();
                } else {
                    $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
