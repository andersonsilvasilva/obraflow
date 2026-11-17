<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Itens do orçamento. Etapa da MESMA versão: FK (tenant_id, budget_version_id, group_id).
 * Autossuficientes (D-08): código, descrição, unidade e custo são CÓPIAS; `source_ref` registra a origem
 * (base, competência, UF, regime) SEM FK. Precisão D-P32: quantidade 4 casas, preços e totais 2.
 * `bdi_percent` NULL = herda (etapa → global). Totais calculados pelo servidor (BudgetCalculator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_items', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('budget_version_id');
            $table->unsignedBigInteger('group_id');
            $table->string('code', 30);
            $table->text('description');
            $table->string('unit_code', 20);
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('bdi_percent', 7, 4)->nullable();
            $table->decimal('discount_percent', 7, 4)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->decimal('total_sale', 15, 2)->default(0);
            $table->string('source', 20)->default('manual');
            $table->json('source_ref')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            RS::tenantKey($table, 'buditem_tenant_id_unique');
            $table->unique(['tenant_id', 'budget_version_id', 'id'], 'buditem_version_id_unique');
            RS::ownerFk($table, 'budget_version_id', 'budget_versions', 'buditem_version_fk');
            $table->foreign(['tenant_id', 'budget_version_id', 'group_id'], 'buditem_group_fk')
                ->references(['tenant_id', 'budget_version_id', 'id'])->on('budget_groups')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->index(['tenant_id', 'group_id', 'sort_order'], 'buditem_order_idx');
        });

        DB::statement("ALTER TABLE budget_items ADD CONSTRAINT chk_buditem_source CHECK (source IN ('official','custom','manual'))");
        DB::statement('ALTER TABLE budget_items ADD CONSTRAINT chk_buditem_values CHECK (quantity >= 0 AND unit_cost >= 0 AND total_cost >= 0 AND total_sale >= 0)');
        DB::statement('ALTER TABLE budget_items ADD CONSTRAINT chk_buditem_pct CHECK ((bdi_percent IS NULL OR (bdi_percent >= 0 AND bdi_percent < 1000)) AND discount_percent >= 0 AND discount_percent < 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_items');
    }
};
