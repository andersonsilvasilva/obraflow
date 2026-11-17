<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Balanço" da mão de obra (D-P39): histórico por data de vigência. `hourly_cost` é gravado pelo serviço:
 * arred₂(salário base × (1 + encargos%) × (1 + administração%) ÷ carga horária mensal). Sem cálculo de encargos
 * por rubrica (coerente com D-P03). Quem registrou é membro do tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_labor_costs', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('resource_id');
            $table->date('effective_date');
            $table->decimal('base_salary', 15, 2);
            $table->decimal('extra_hour', 15, 2)->nullable();
            $table->decimal('charges_percent', 7, 4)->default(0);
            $table->decimal('admin_percent', 7, 4)->default(0);
            $table->decimal('monthly_hours', 7, 2);
            $table->string('labor_type', 10)->nullable();
            $table->string('contract_type', 12)->nullable();
            $table->decimal('hourly_cost', 15, 2);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            RS::tenantKey($table, 'reslab_tenant_id_unique');
            RS::ownerFk($table, 'resource_id', 'resources', 'reslab_resource_fk');
            $table->foreign(['tenant_id', 'created_by'], 'reslab_creator_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['tenant_id', 'resource_id', 'effective_date'], 'reslab_one_per_date');
        });

        DB::statement('ALTER TABLE resource_labor_costs ADD CONSTRAINT chk_reslab_values CHECK (base_salary >= 0 AND monthly_hours > 0 AND charges_percent >= 0 AND admin_percent >= 0 AND hourly_cost >= 0 AND (extra_hour IS NULL OR extra_hour >= 0))');
        DB::statement("ALTER TABLE resource_labor_costs ADD CONSTRAINT chk_reslab_labor CHECK (labor_type IS NULL OR labor_type IN ('direct','indirect'))");
        DB::statement("ALTER TABLE resource_labor_costs ADD CONSTRAINT chk_reslab_contract CHECK (contract_type IS NULL OR contract_type IN ('monthly','hourly','piecework','daily'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_labor_costs');
    }
};
