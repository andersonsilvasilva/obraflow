<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custos indiretos da versão (D-P02): linhas próprias, FORA do BDI — valor fixo ou % do custo direto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_indirect_costs', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('budget_version_id');
            $table->string('description', 200);
            $table->string('method', 20);
            $table->decimal('percent', 7, 4)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            RS::tenantKey($table, 'budind_tenant_id_unique');
            RS::ownerFk($table, 'budget_version_id', 'budget_versions', 'budind_version_fk');
        });

        DB::statement("ALTER TABLE budget_indirect_costs ADD CONSTRAINT chk_budind_method CHECK (
            (method = 'fixed' AND amount IS NOT NULL AND amount >= 0 AND percent IS NULL)
            OR (method = 'percent_of_direct' AND percent IS NOT NULL AND percent >= 0 AND percent < 1000 AND amount IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_indirect_costs');
    }
};
