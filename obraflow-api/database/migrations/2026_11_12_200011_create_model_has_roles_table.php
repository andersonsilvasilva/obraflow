<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Atribuição de papéis, com integridade REFORÇADA em relação ao stub do Spatie (D-19):
 *  - FK composta (tenant_id, role_id) → roles(tenant_id, id): o papel pertence ao mesmo tenant do vínculo;
 *  - FK composta (tenant_id, model_id) → tenant_users(tenant_id, user_id): o usuário tem vínculo no tenant;
 *  - CHECK model_type = 'user' (exige Relation::enforceMorphMap);
 *  - RESTRICT (o stub usa CASCADE): remover atribuição é uma ação explícita e auditada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('tenant_id');

            $table->primary(['tenant_id', 'role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
            $table->index(['model_id', 'model_type'], 'mhr_model_idx');
            $table->index(['tenant_id', 'model_id'], 'mhr_tenant_model_idx');

            $table->foreign(['tenant_id', 'role_id'], 'fk_mhr_role')->references(['tenant_id', 'id'])->on('roles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tenant_id', 'model_id'], 'fk_mhr_member')->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE model_has_roles ADD CONSTRAINT chk_mhr_type CHECK (model_type = 'user')");
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_roles');
    }
};
