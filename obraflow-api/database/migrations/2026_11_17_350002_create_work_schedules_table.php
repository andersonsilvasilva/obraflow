<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horário de trabalho (D-P63): padrão da EMPRESA ou horário próprio da OBRA — exatamente um dono (CHECK), por FK
 * direta (sem polimorfismo). Uma linha por dia da semana (0 = domingo … 6 = sábado). Até 2 períodos por dia; dia sem
 * período = não trabalhado (D-P64). A obra sem linhas próprias herda o padrão da empresa dela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_1')->nullable();
            $table->time('end_1')->nullable();
            $table->time('start_2')->nullable();
            $table->time('end_2')->nullable();
            $table->timestamps();

            RS::tenantKey($table, 'wsch_tenant_id_unique');
            RS::ownerFk($table, 'company_id', 'companies', 'wsch_company_fk');
            RS::ownerFk($table, 'project_id', 'projects', 'wsch_project_fk');
            $table->unique(['tenant_id', 'company_id', 'weekday'], 'wsch_company_day_unique');
            $table->unique(['tenant_id', 'project_id', 'weekday'], 'wsch_project_day_unique');
        });

        DB::statement('ALTER TABLE work_schedules ADD CONSTRAINT chk_wsch_owner CHECK ((company_id IS NULL) <> (project_id IS NULL))');
        DB::statement('ALTER TABLE work_schedules ADD CONSTRAINT chk_wsch_weekday CHECK (weekday BETWEEN 0 AND 6)');
        DB::statement('ALTER TABLE work_schedules ADD CONSTRAINT chk_wsch_pairs CHECK ((start_1 IS NULL) = (end_1 IS NULL) AND (start_2 IS NULL) = (end_2 IS NULL))');
        DB::statement('ALTER TABLE work_schedules ADD CONSTRAINT chk_wsch_order CHECK ((start_1 IS NULL OR end_1 > start_1)
            AND (start_2 IS NULL OR (start_1 IS NOT NULL AND start_2 >= end_1 AND end_2 > start_2)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
