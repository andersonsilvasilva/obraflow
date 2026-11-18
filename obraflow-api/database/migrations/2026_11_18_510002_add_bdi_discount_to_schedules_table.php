<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BDI e desconto do planejamento (6B, D-P92): botões "BDI" e "Desconto". Venda = custo × (1 + BDI) × (1 − desconto),
 * a mesma conta do orçamento (D-P72). `bdi_components` guarda as parcelas quando o BDI vem da fórmula do TCU.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->decimal('bdi_percent', 7, 4)->default(0)->after('budget_version_id');
            $table->json('bdi_components')->nullable()->after('bdi_percent');
            $table->decimal('discount_percent', 7, 4)->default(0)->after('bdi_components');
        });
        DB::statement('ALTER TABLE schedules ADD CONSTRAINT chk_sched_bdi CHECK (bdi_percent >= 0 AND bdi_percent < 1000)');
        DB::statement('ALTER TABLE schedules ADD CONSTRAINT chk_sched_discount CHECK (discount_percent >= 0 AND discount_percent < 100)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE schedules DROP CHECK chk_sched_bdi');
        DB::statement('ALTER TABLE schedules DROP CHECK chk_sched_discount');
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn(['bdi_percent', 'bdi_components', 'discount_percent']);
        });
    }
};
