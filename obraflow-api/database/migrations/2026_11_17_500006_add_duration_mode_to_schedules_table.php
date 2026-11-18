<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration de AJUSTE (A-51, ajusta a D-P66 a pedido do responsável): cada planejamento escolhe como conta a duração —
 * `calendar` (dias corridos, como o Brickup; padrão) ou `working` (dias úteis da obra: horário + calendário).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->string('duration_mode', 10)->default('calendar')->after('origin');
        });
        DB::statement("ALTER TABLE schedules ADD CONSTRAINT chk_sched_duration_mode CHECK (duration_mode IN ('calendar','working'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE schedules DROP CHECK chk_sched_duration_mode');
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn('duration_mode');
        });
    }
};
