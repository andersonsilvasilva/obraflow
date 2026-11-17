<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration de AJUSTE (A-47, pedido do responsável — cadastro da obra como o do Brickup): dados técnicos da obra.
 * - área a ser construída (m²);
 * - prazo em meses (a previsão de entrega é calculada a partir da data inicial quando não informada);
 * - CNO — Cadastro Nacional de Obras (Receita Federal), 12 dígitos, gravado só com dígitos;
 * - encargo social da obra (D-P03): com desoneração, sem desoneração ou sem encargo — padrão dos orçamentos da obra.
 * O endereço do canteiro e as coordenadas do mapa ficam em project_addresses (já existentes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('built_area', 12, 2)->nullable()->after('contract_value');
            $table->unsignedSmallInteger('duration_months')->nullable()->after('built_area');
            $table->char('cno', 12)->charset('ascii')->collation('ascii_bin')->nullable()->after('duration_months');
            $table->string('social_charges_regime', 20)->nullable()->after('cno');
        });

        DB::statement('ALTER TABLE projects ADD CONSTRAINT chk_proj_built_area CHECK (built_area IS NULL OR built_area > 0)');
        DB::statement('ALTER TABLE projects ADD CONSTRAINT chk_proj_duration CHECK (duration_months IS NULL OR duration_months BETWEEN 1 AND 600)');
        DB::statement("ALTER TABLE projects ADD CONSTRAINT chk_proj_cno CHECK (cno IS NULL OR REGEXP_LIKE(cno, '^[0-9]{12}$'))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT chk_proj_regime CHECK (social_charges_regime IS NULL OR social_charges_regime IN ('with_relief','without_relief','not_applicable'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE projects DROP CHECK chk_proj_built_area');
        DB::statement('ALTER TABLE projects DROP CHECK chk_proj_duration');
        DB::statement('ALTER TABLE projects DROP CHECK chk_proj_cno');
        DB::statement('ALTER TABLE projects DROP CHECK chk_proj_regime');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['built_area', 'duration_months', 'cno', 'social_charges_regime']);
        });
    }
};
