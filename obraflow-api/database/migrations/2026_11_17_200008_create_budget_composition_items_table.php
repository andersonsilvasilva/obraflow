<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Itens diretos da composição copiada (insumos e composições auxiliares), como publicados: coeficiente de 7 casas,
 * preço unitário e custo. Composição da MESMA versão (FK com budget_version_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_composition_items', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('budget_version_id');
            $table->unsignedBigInteger('budget_composition_id');
            $table->string('item_type', 20); // resource | composition
            $table->string('kind', 20)->nullable(); // tipo do insumo (material, labor, equipment, service)
            $table->string('code', 30);
            $table->text('description');
            $table->string('unit_code', 20);
            $table->decimal('coefficient', 18, 7);
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('cost', 15, 2)->nullable();
            $table->string('price_origin', 40)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            RS::tenantKey($table, 'budcompi_tenant_id_unique');
            RS::ownerFk($table, 'budget_version_id', 'budget_versions', 'budcompi_version_fk');
            $table->foreign(['tenant_id', 'budget_version_id', 'budget_composition_id'], 'budcompi_composition_fk')
                ->references(['tenant_id', 'budget_version_id', 'id'])->on('budget_compositions')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE budget_composition_items ADD CONSTRAINT chk_budcompi_type CHECK (item_type IN ('resource','composition'))");
        DB::statement("ALTER TABLE budget_composition_items ADD CONSTRAINT chk_budcompi_kind CHECK (kind IS NULL OR kind IN ('material','labor','equipment','service'))");
        DB::statement('ALTER TABLE budget_composition_items ADD CONSTRAINT chk_budcompi_coef CHECK (coefficient >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_composition_items');
    }
};
