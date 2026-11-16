<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fases da obra (árvore). A fase-pai é da MESMA obra: FK (tenant_id, project_id, parent_id)
 * → project_phases (tenant_id, project_id, id). Até 3 níveis (regra da aplicação).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_phases', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 150);
            $table->unsignedInteger('sort_order')->default(0);
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->timestamps();

            RS::tenantKey($table, 'prjph_tenant_id_unique');
            $table->unique(['tenant_id', 'project_id', 'id'], 'prjph_tenant_project_id_unique');
            RS::ownerFk($table, 'project_id', 'projects', 'prjph_project_fk');
            $table->foreign(['tenant_id', 'project_id', 'parent_id'], 'prjph_parent_fk')
                ->references(['tenant_id', 'project_id', 'id'])->on('project_phases')
                ->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE project_phases ADD CONSTRAINT chk_prjph_dates CHECK (planned_end IS NULL OR planned_start IS NULL OR planned_end >= planned_start)');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_phases');
    }
};
