<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dependências (predecessoras) do planejamento (6A, D-P67): término→início (FS), início→início (SS),
 * término→término (FF), início→término (SF), com atraso em dias úteis (pode ser negativo: adiantamento).
 * As duas pontas no MESMO planejamento (FK compostas); ciclo é recusado pela aplicação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_dependencies', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('predecessor_id');
            $table->unsignedBigInteger('successor_id');
            $table->string('type', 2)->default('FS');
            $table->smallInteger('lag_days')->default(0);
            $table->timestamps();

            RS::tenantKey($table, 'schdep_tenant_id_unique');
            $table->unique(['tenant_id', 'predecessor_id', 'successor_id'], 'schdep_pair_unique');
            RS::ownerFk($table, 'schedule_id', 'schedules', 'schdep_schedule_fk');
            $table->foreign(['tenant_id', 'schedule_id', 'predecessor_id'], 'schdep_pred_fk')
                ->references(['tenant_id', 'schedule_id', 'id'])->on('schedule_tasks')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tenant_id', 'schedule_id', 'successor_id'], 'schdep_succ_fk')
                ->references(['tenant_id', 'schedule_id', 'id'])->on('schedule_tasks')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE schedule_dependencies ADD CONSTRAINT chk_schdep_type CHECK (type IN ('FS','SS','FF','SF'))");
        DB::statement('ALTER TABLE schedule_dependencies ADD CONSTRAINT chk_schdep_self CHECK (predecessor_id <> successor_id)');
        DB::statement('ALTER TABLE schedule_dependencies ADD CONSTRAINT chk_schdep_lag CHECK (lag_days BETWEEN -365 AND 365)');
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_dependencies');
    }
};
