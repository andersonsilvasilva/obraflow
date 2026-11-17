<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Insumos FINAIS de cada item, com a árvore de composições expandida no momento da cópia: coeficiente acumulado
 * POR UNIDADE do item e preço publicado. Base da Curva ABC de insumos e da consolidação de insumos da versão
 * (calculadas sob demanda — D-P33; substituem `budget_resources`/`budget_resource_items` do plano original).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_item_resources', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('budget_version_id');
            $table->unsignedBigInteger('budget_item_id');
            $table->string('kind', 20)->nullable();
            $table->string('code', 30);
            $table->text('description');
            $table->string('unit_code', 20);
            $table->decimal('coefficient', 18, 7);
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->timestamps();

            RS::tenantKey($table, 'buditres_tenant_id_unique');
            RS::ownerFk($table, 'budget_version_id', 'budget_versions', 'buditres_version_fk');
            $table->foreign(['tenant_id', 'budget_version_id', 'budget_item_id'], 'buditres_item_fk')
                ->references(['tenant_id', 'budget_version_id', 'id'])->on('budget_items')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['tenant_id', 'budget_item_id', 'code'], 'buditres_item_code_unique');
            $table->index(['tenant_id', 'budget_version_id', 'code'], 'buditres_version_code_idx');
        });

        DB::statement("ALTER TABLE budget_item_resources ADD CONSTRAINT chk_buditres_kind CHECK (kind IS NULL OR kind IN ('material','labor','equipment','service'))");
        DB::statement('ALTER TABLE budget_item_resources ADD CONSTRAINT chk_buditres_coef CHECK (coefficient >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_item_resources');
    }
};
