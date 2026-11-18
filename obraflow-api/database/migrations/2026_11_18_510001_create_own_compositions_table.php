<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Composição própria SIMPLIFICADA da organização (6B, D-P87; retoma a D-P42 sem coeficientes): um valor por tipo
 * para uma unidade do serviço. Equipamento e mão de obra têm três valores, conforme o regime de encargos
 * (sem encargos, desonerado, não desonerado — D-P88). Reutilizável em qualquer obra; lançada no planejamento por cópia.
 */
return new class extends Migration
{
    private const VALUES = ['material_value', 'service_value', 'other_value', 'equipment_value_none', 'equipment_value_relief',
        'equipment_value_no_relief', 'labor_value_none', 'labor_value_relief', 'labor_value_no_relief'];

    public function up(): void
    {
        Schema::create('own_compositions', function (Blueprint $table) {
            RS::base($table);
            $table->uuid('uuid')->unique();
            $table->string('code', 30)->nullable();
            $table->string('name', 250);
            $table->unsignedBigInteger('unit_id');
            $table->date('reference_date');
            $table->char('state', 2)->nullable();
            foreach (self::VALUES as $column) {
                $table->decimal($column, 15, 2)->default(0);
            }
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            RS::softDeleteWithMarker($table);

            RS::tenantKey($table, 'owncomp_tenant_id_unique');
            RS::ownerFk($table, 'unit_id', 'units_of_measure', 'owncomp_unit_fk');
            $table->unique(['tenant_id', 'code', 'active_marker'], 'owncomp_code_active');
            $table->index(['tenant_id', 'status'], 'owncomp_status_idx');
        });

        RS::statusCheck('own_compositions');
        DB::statement('ALTER TABLE own_compositions ADD CONSTRAINT chk_owncomp_values CHECK ('.implode(' AND ', array_map(fn ($c) => "{$c} >= 0", self::VALUES)).')');
        DB::statement("ALTER TABLE own_compositions ADD CONSTRAINT chk_owncomp_state CHECK (state IS NULL OR REGEXP_LIKE(state, '^[A-Z]{2}$', 'c'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('own_compositions');
    }
};
