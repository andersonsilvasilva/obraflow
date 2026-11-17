<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Itens de composição: um insumo OU uma composição filha (CHECK), com coeficiente de 7 casas (D-P32) e o preço/custo
 * publicados na linha. FKs (dataset_id, x_id) garantem que insumo, composição e composição filha são da MESMA base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_composition_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dataset_id');
            $table->unsignedBigInteger('composition_id');
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedBigInteger('child_composition_id')->nullable();
            $table->decimal('coefficient', 18, 7);
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('cost', 15, 2)->nullable();
            $table->string('price_origin', 40)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('dataset_id', 'refitem_dataset_fk')->references('id')->on('reference_datasets')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['dataset_id', 'composition_id'], 'refitem_composition_fk')
                ->references(['dataset_id', 'id'])->on('reference_compositions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['dataset_id', 'resource_id'], 'refitem_resource_fk')
                ->references(['dataset_id', 'id'])->on('reference_resources')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['dataset_id', 'child_composition_id'], 'refitem_child_fk')
                ->references(['dataset_id', 'id'])->on('reference_compositions')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['composition_id', 'sort_order'], 'refitem_composition_idx');
        });

        DB::statement('ALTER TABLE reference_composition_items ADD CONSTRAINT chk_refitem_target CHECK ((resource_id IS NULL) <> (child_composition_id IS NULL))');
        DB::statement('ALTER TABLE reference_composition_items ADD CONSTRAINT chk_refitem_coef CHECK (coefficient >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_composition_items');
    }
};
