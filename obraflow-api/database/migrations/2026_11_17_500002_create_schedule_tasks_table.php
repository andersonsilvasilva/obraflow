<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Atividades do planejamento (6A). Etapa (`summary`) agrupa filhas e cobre o período delas; atividade (`task`) tem
 * duração em DIAS ÚTEIS (D-P66); marco (`milestone`) tem duração 0. Datas e duração são recalculadas pelo
 * Scheduler (reprogramação e caminho crítico, D-P67). Pai e filhos no MESMO planejamento (FK composta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_tasks', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('wbs_code', 40);
            $table->string('name', 250);
            $table->string('kind', 20);
            $table->string('unit_code', 20)->nullable();
            $table->decimal('quantity', 15, 4)->nullable();
            $table->date('planned_start');
            $table->date('planned_end');
            $table->unsignedSmallInteger('duration_days');
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();
            $table->decimal('percent_complete', 5, 2)->default(0);
            $table->unsignedBigInteger('budget_item_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            RS::tenantKey($table, 'schtask_tenant_id_unique');
            $table->unique(['tenant_id', 'schedule_id', 'id'], 'schtask_schedule_id_unique');
            RS::ownerFk($table, 'schedule_id', 'schedules', 'schtask_schedule_fk');
            $table->foreign(['tenant_id', 'schedule_id', 'parent_id'], 'schtask_parent_fk')
                ->references(['tenant_id', 'schedule_id', 'id'])->on('schedule_tasks')->restrictOnDelete()->restrictOnUpdate();
            RS::ownerFk($table, 'budget_item_id', 'budget_items', 'schtask_budget_item_fk');
            $table->index(['tenant_id', 'schedule_id', 'parent_id', 'sort_order'], 'schtask_tree_idx');
        });

        DB::statement("ALTER TABLE schedule_tasks ADD CONSTRAINT chk_schtask_kind CHECK (kind IN ('summary','task','milestone'))");
        DB::statement('ALTER TABLE schedule_tasks ADD CONSTRAINT chk_schtask_dates CHECK (planned_end >= planned_start AND (actual_end IS NULL OR actual_start IS NULL OR actual_end >= actual_start))');
        DB::statement('ALTER TABLE schedule_tasks ADD CONSTRAINT chk_schtask_percent CHECK (percent_complete BETWEEN 0 AND 100)');
        DB::statement("ALTER TABLE schedule_tasks ADD CONSTRAINT chk_schtask_milestone CHECK (kind <> 'milestone' OR (duration_days = 0 AND planned_end = planned_start))");
        DB::statement("ALTER TABLE schedule_tasks ADD CONSTRAINT chk_schtask_task CHECK (kind <> 'task' OR duration_days >= 1)");
        DB::statement('ALTER TABLE schedule_tasks ADD CONSTRAINT chk_schtask_quantity CHECK (quantity IS NULL OR quantity >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_tasks');
    }
};
