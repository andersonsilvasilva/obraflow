<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planejamento da obra (Fase 6A, FASE6_MODELO.md). UM por obra (UNIQUE). Origem registrada (D-P68): orçamento
 * aprovado, planilha ou em branco; a versão de orçamento de origem é opcional (imutável — FK segura).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            RS::base($table);
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('project_id');
            $table->string('origin', 20);
            $table->unsignedBigInteger('budget_version_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            RS::tenantKey($table, 'sched_tenant_id_unique');
            $table->unique(['tenant_id', 'project_id'], 'sched_project_unique');
            RS::ownerFk($table, 'project_id', 'projects', 'sched_project_fk');
            RS::ownerFk($table, 'budget_version_id', 'budget_versions', 'sched_budget_version_fk');
            $table->foreign(['tenant_id', 'created_by'], 'sched_creator_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE schedules ADD CONSTRAINT chk_sched_origin CHECK (origin IN ('budget','spreadsheet','blank'))");
        DB::statement("ALTER TABLE schedules ADD CONSTRAINT chk_sched_budget CHECK (origin <> 'budget' OR budget_version_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
