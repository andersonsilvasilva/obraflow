<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composição COPIADA para um item (D-08): descrição, unidade, custo e a origem (base, competência, UF, regime)
 * em `source_snapshot`. Nenhuma FK para as referências — o orçamento continua íntegro se a base for substituída.
 * Uma composição por item; item da MESMA versão (FK com budget_version_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_compositions', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('budget_version_id');
            $table->unsignedBigInteger('budget_item_id');
            $table->string('code', 30);
            $table->text('description');
            $table->string('unit_code', 20);
            $table->decimal('unit_cost', 15, 2);
            $table->json('source_snapshot');
            $table->timestamps();

            RS::tenantKey($table, 'budcomp_tenant_id_unique');
            $table->unique(['tenant_id', 'budget_version_id', 'id'], 'budcomp_version_id_unique');
            $table->unique(['tenant_id', 'budget_item_id'], 'budcomp_one_per_item');
            RS::ownerFk($table, 'budget_version_id', 'budget_versions', 'budcomp_version_fk');
            $table->foreign(['tenant_id', 'budget_version_id', 'budget_item_id'], 'budcomp_item_fk')
                ->references(['tenant_id', 'budget_version_id', 'id'])->on('budget_items')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_compositions');
    }
};
