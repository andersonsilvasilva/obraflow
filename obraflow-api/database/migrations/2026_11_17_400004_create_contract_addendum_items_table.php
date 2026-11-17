<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Linhas do aditivo (D-P48). `change`: altera a quantidade de um item existente do MESMO contrato (sem mudar o preço —
 * reajuste fica fora, D-P50). `new`: inclui item (código, descrição, unidade, preço, quantidade > 0); ao aprovar, o item
 * é criado e `contract_item_id` passa a apontá-lo. Tudo pelo mesmo contrato (FKs compostas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_addendum_items', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('addendum_id');
            $table->string('line_type', 10);
            $table->unsignedBigInteger('contract_item_id')->nullable();
            $table->string('code', 30)->nullable();
            $table->text('description')->nullable();
            $table->string('unit_code', 20)->nullable();
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('quantity_delta', 15, 4);
            $table->timestamps();

            RS::tenantKey($table, 'ctradi_tenant_id_unique');
            RS::ownerFk($table, 'contract_id', 'contracts', 'ctradi_contract_fk');
            $table->foreign(['tenant_id', 'contract_id', 'addendum_id'], 'ctradi_addendum_fk')
                ->references(['tenant_id', 'contract_id', 'id'])->on('contract_addenda')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tenant_id', 'contract_id', 'contract_item_id'], 'ctradi_item_fk')
                ->references(['tenant_id', 'contract_id', 'id'])->on('contract_items')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['tenant_id', 'addendum_id'], 'ctradi_addendum_idx');
        });

        DB::statement("ALTER TABLE contract_addendum_items ADD CONSTRAINT chk_ctradi_type CHECK (line_type IN ('change','new'))");
        DB::statement("ALTER TABLE contract_addendum_items ADD CONSTRAINT chk_ctradi_line CHECK (
            (line_type = 'change' AND contract_item_id IS NOT NULL AND quantity_delta <> 0)
            OR (line_type = 'new' AND description IS NOT NULL AND unit_code IS NOT NULL AND unit_price IS NOT NULL
                AND unit_price >= 0 AND quantity_delta > 0))");
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_addendum_items');
    }
};
