<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** RESTRICT (o stub usa CASCADE): o desvínculo de permissões de um papel é explícito e auditado. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');

            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');

            $table->foreign('permission_id', 'fk_rhp_permission')->references('id')->on('permissions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('role_id', 'fk_rhp_role')->references('id')->on('roles')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
    }
};
