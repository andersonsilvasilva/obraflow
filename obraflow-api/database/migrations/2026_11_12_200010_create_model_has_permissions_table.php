<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permissões diretas NÃO são usadas no MVP (tudo por papel); a tabela existe porque o pacote a exige
 * e já nasce com a mesma proteção de model_has_roles (D-19).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('tenant_id');

            $table->primary(['tenant_id', 'permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
            $table->index(['model_id', 'model_type'], 'mhp_model_idx');
            $table->index(['tenant_id', 'model_id'], 'mhp_tenant_model_idx');

            $table->foreign('permission_id', 'fk_mhp_permission')->references('id')->on('permissions')->restrictOnDelete()->restrictOnUpdate();
            // usuário precisa ter vínculo com o tenant
            $table->foreign(['tenant_id', 'model_id'], 'fk_mhp_member')->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE model_has_permissions ADD CONSTRAINT chk_mhp_type CHECK (model_type = 'user')");
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_permissions');
    }
};
