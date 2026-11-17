<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BDI da versão (D-P02): global, por etapa ou por item. `method` informed (percentual digitado) ou tcu
 * (calculado pela fórmula do TCU a partir de `components`). Etapa/item da MESMA versão (FKs com budget_version_id).
 * `scope_key` (gerada) garante um único BDI por alvo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_bdi', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('budget_version_id');
            $table->string('scope', 10);
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('method', 10)->default('informed');
            $table->json('components')->nullable();
            $table->decimal('percent', 7, 4);
            $table->string('scope_key', 40)->virtualAs("CONCAT(scope, ':', IFNULL(group_id, IFNULL(item_id, 0)))");
            $table->timestamps();

            RS::tenantKey($table, 'budbdi_tenant_id_unique');
            RS::ownerFk($table, 'budget_version_id', 'budget_versions', 'budbdi_version_fk');
            $table->foreign(['tenant_id', 'budget_version_id', 'group_id'], 'budbdi_group_fk')
                ->references(['tenant_id', 'budget_version_id', 'id'])->on('budget_groups')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tenant_id', 'budget_version_id', 'item_id'], 'budbdi_item_fk')
                ->references(['tenant_id', 'budget_version_id', 'id'])->on('budget_items')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['tenant_id', 'budget_version_id', 'scope_key'], 'budbdi_one_per_target');
        });

        DB::statement("ALTER TABLE budget_bdi ADD CONSTRAINT chk_budbdi_scope CHECK (
            (scope = 'global' AND group_id IS NULL AND item_id IS NULL)
            OR (scope = 'group' AND group_id IS NOT NULL AND item_id IS NULL)
            OR (scope = 'item' AND item_id IS NOT NULL AND group_id IS NULL))");
        DB::statement("ALTER TABLE budget_bdi ADD CONSTRAINT chk_budbdi_method CHECK (method IN ('informed','tcu') AND (method <> 'tcu' OR components IS NOT NULL))");
        DB::statement('ALTER TABLE budget_bdi ADD CONSTRAINT chk_budbdi_percent CHECK (percent >= 0 AND percent < 1000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_bdi');
    }
};
