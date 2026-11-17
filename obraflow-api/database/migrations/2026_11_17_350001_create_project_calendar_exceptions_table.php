<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exceções do calendário da obra — dias SEM trabalho (CALENDARIO_OBRA_MODELO.md, D-P57/D-P58). A regra de repetição
 * é gravada UMA vez; as ocorrências são calculadas para o período pedido (não há uma linha por dia).
 * Configuração da obra (não histórico): pode ser excluída, com auditoria (D-P62).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_calendar_exceptions', function (Blueprint $table) {
            RS::base($table);
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('project_id');
            $table->string('description', 200);
            $table->date('date');
            $table->string('kind', 20);
            $table->unsignedSmallInteger('repeat_every')->nullable();
            $table->string('repeat_unit', 20)->nullable();
            $table->date('repeat_until')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            RS::tenantKey($table, 'pcalex_tenant_id_unique');
            RS::ownerFk($table, 'project_id', 'projects', 'pcalex_project_fk');
            $table->foreign(['tenant_id', 'created_by'], 'pcalex_creator_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['tenant_id', 'project_id', 'date'], 'pcalex_project_date_idx');
        });

        DB::statement("ALTER TABLE project_calendar_exceptions ADD CONSTRAINT chk_pcalex_kind CHECK (kind IN ('single','recurring'))");
        DB::statement("ALTER TABLE project_calendar_exceptions ADD CONSTRAINT chk_pcalex_repeat CHECK (
            (kind = 'single' AND repeat_every IS NULL AND repeat_unit IS NULL AND repeat_until IS NULL)
            OR (kind = 'recurring' AND repeat_every IS NOT NULL AND repeat_unit IS NOT NULL AND repeat_every >= 1 AND repeat_unit IN ('day','week','fortnight','month','year')))");
        DB::statement('ALTER TABLE project_calendar_exceptions ADD CONSTRAINT chk_pcalex_until CHECK (repeat_until IS NULL OR repeat_until >= date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_calendar_exceptions');
    }
};
