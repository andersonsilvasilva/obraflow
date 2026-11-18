<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recursos de cada atividade do planejamento (6B): catálogo (4C), insumo ou composição SINAPI (4A), composição própria,
 * item do orçamento ou digitado. Sempre CÓPIA do preço no momento (D-08), com o custo unitário separado por tipo
 * (material, mão de obra, equipamento, serviço, outros) — base das 28 colunas do orçamento sintético (D-P73/D-P96).
 * Consumo por unidade da atividade (D-P91). Atividade do MESMO planejamento (FK composta); "só em atividade" é regra
 * da aplicação (o tipo da tarefa pode mudar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_task_items', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('task_id');
            $table->string('source', 20);
            $table->json('source_ref')->nullable();
            $table->string('kind', 20);
            $table->string('code', 30)->nullable();
            $table->string('description', 500);
            $table->string('unit_code', 20);
            $table->decimal('quantity_per_unit', 18, 7);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('cost_material', 15, 2)->default(0);
            $table->decimal('cost_labor', 15, 2)->default(0);
            $table->decimal('cost_equipment', 15, 2)->default(0);
            $table->decimal('cost_service', 15, 2)->default(0);
            $table->decimal('cost_other', 15, 2)->default(0);
            $table->decimal('bdi_percent', 7, 4)->nullable();
            $table->decimal('discount_percent', 7, 4)->nullable();
            $table->json('breakdown')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            RS::tenantKey($table, 'schitem_tenant_id_unique');
            RS::ownerFk($table, 'schedule_id', 'schedules', 'schitem_schedule_fk');
            $table->foreign(['tenant_id', 'schedule_id', 'task_id'], 'schitem_task_fk')
                ->references(['tenant_id', 'schedule_id', 'id'])->on('schedule_tasks')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['tenant_id', 'task_id', 'sort_order'], 'schitem_task_idx');
        });

        DB::statement("ALTER TABLE schedule_task_items ADD CONSTRAINT chk_schitem_source CHECK (source IN ('catalog','sinapi_resource','sinapi_composition','own_composition','budget','manual'))");
        DB::statement("ALTER TABLE schedule_task_items ADD CONSTRAINT chk_schitem_kind CHECK (kind IN ('material','labor','equipment','service','other','composition'))");
        DB::statement('ALTER TABLE schedule_task_items ADD CONSTRAINT chk_schitem_values CHECK (quantity_per_unit >= 0 AND unit_cost >= 0 AND cost_material >= 0 AND cost_labor >= 0 AND cost_equipment >= 0 AND cost_service >= 0 AND cost_other >= 0)');
        DB::statement('ALTER TABLE schedule_task_items ADD CONSTRAINT chk_schitem_split CHECK (cost_material + cost_labor + cost_equipment + cost_service + cost_other = unit_cost)');
        DB::statement('ALTER TABLE schedule_task_items ADD CONSTRAINT chk_schitem_pct CHECK ((bdi_percent IS NULL OR (bdi_percent >= 0 AND bdi_percent < 1000)) AND (discount_percent IS NULL OR (discount_percent >= 0 AND discount_percent < 100)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_task_items');
    }
};
