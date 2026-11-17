<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Itens do contrato (planilha contratual). `original_quantity` = quantidade da assinatura (0 em item incluído por
 * aditivo); `quantity` = quantidade atual (com aditivos aprovados); `total` = arred2(quantity × unit_price) (D-P32).
 * Vínculo opcional ao item do orçamento aprovado (D-P47: a versão aprovada é imutável, a FK é segura).
 * Item incluído por aditivo aponta o aditivo do MESMO contrato (FK composta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_items', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('addendum_id')->nullable();
            $table->unsignedBigInteger('budget_item_id')->nullable();
            $table->string('code', 30)->nullable();
            $table->text('description');
            $table->string('unit_code', 20);
            $table->decimal('original_quantity', 15, 4);
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            RS::tenantKey($table, 'ctritem_tenant_id_unique');
            $table->unique(['tenant_id', 'contract_id', 'id'], 'ctritem_contract_id_unique'); // alvo das FKs (aditivos, medições)
            RS::ownerFk($table, 'contract_id', 'contracts', 'ctritem_contract_fk');
            $table->foreign(['tenant_id', 'contract_id', 'addendum_id'], 'ctritem_addendum_fk')
                ->references(['tenant_id', 'contract_id', 'id'])->on('contract_addenda')->restrictOnDelete()->restrictOnUpdate();
            RS::ownerFk($table, 'budget_item_id', 'budget_items', 'ctritem_budget_item_fk');
            $table->index(['tenant_id', 'contract_id', 'sort_order'], 'ctritem_order_idx');
        });

        DB::statement('ALTER TABLE contract_items ADD CONSTRAINT chk_ctritem_values CHECK (original_quantity >= 0 AND quantity >= 0 AND unit_price >= 0 AND total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_items');
    }
};
