<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baseada no stub do Spatie, com divergências DELIBERADAS (D-18):
 *  - tenant_id é NOT NULL: não existem papéis globais; Role::create sem contexto falha no banco;
 *  - FK para tenants (o stub não cria);
 *  - UNIQUE (tenant_id, id): alvo das FKs compostas de model_has_roles e user_invitations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete()->restrictOnUpdate();
            $table->string('name', 125);
            $table->string('guard_name', 125);
            $table->boolean('is_system')->default(false); // criado no provisionamento; não pode ser excluído
            $table->timestamps();

            $table->unique(['tenant_id', 'name', 'guard_name'], 'roles_tenant_name_guard_unique');
            $table->unique(['tenant_id', 'id'], 'roles_tenant_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
