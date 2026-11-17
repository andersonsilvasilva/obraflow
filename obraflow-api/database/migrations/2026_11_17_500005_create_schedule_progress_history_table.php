<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico do avanço das atividades (6A; o RDO aprovado também grava aqui na 6C — D-P75). Só inserção.
 * `diary_id` recebe a FK para o RDO na migration da 6C.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_progress_history', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('task_id');
            $table->decimal('percent_complete', 5, 2);
            $table->string('source', 10);
            $table->unsignedBigInteger('diary_id')->nullable();
            $table->unsignedBigInteger('recorded_by');
            $table->timestamp('recorded_at', 6)->useCurrent();

            RS::tenantKey($table, 'schprog_tenant_id_unique');
            $table->foreign(['tenant_id', 'task_id'], 'schprog_task_fk')
                ->references(['tenant_id', 'id'])->on('schedule_tasks')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tenant_id', 'recorded_by'], 'schprog_recorder_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['tenant_id', 'task_id', 'recorded_at'], 'schprog_task_idx');
        });

        DB::statement("ALTER TABLE schedule_progress_history ADD CONSTRAINT chk_schprog_source CHECK (source IN ('manual','diary'))");
        DB::statement('ALTER TABLE schedule_progress_history ADD CONSTRAINT chk_schprog_percent CHECK (percent_complete BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_progress_history');
    }
};
