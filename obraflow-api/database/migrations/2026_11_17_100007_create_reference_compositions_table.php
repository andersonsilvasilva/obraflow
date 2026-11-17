<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Composições de uma base, com classificação e custos como publicados (total e decomposição por categoria).
 * UNIQUE (dataset_id, id) é alvo das FKs dos itens (composição filha da MESMA base).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_compositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('reference_datasets')->restrictOnDelete()->restrictOnUpdate();
            $table->string('code', 20);
            $table->text('description');
            $table->foreignId('unit_id')->constrained('reference_units')->restrictOnDelete()->restrictOnUpdate();
            $table->string('class_code', 20)->nullable();
            $table->string('class_name', 150)->nullable();
            $table->string('type_code', 20)->nullable();
            $table->string('type_name', 255)->nullable();
            $table->string('group_code', 20)->nullable();
            $table->string('group_name', 255)->nullable();
            $table->string('price_origin', 40)->nullable();
            $table->decimal('total_cost', 15, 2);
            $table->decimal('labor_cost', 15, 2)->nullable();
            $table->decimal('material_cost', 15, 2)->nullable();
            $table->decimal('equipment_cost', 15, 2)->nullable();
            $table->decimal('services_cost', 15, 2)->nullable();
            $table->decimal('other_cost', 15, 2)->nullable();
            $table->timestamps();

            $table->unique(['dataset_id', 'code'], 'refcomp_dataset_code_unique');
            $table->unique(['dataset_id', 'id'], 'refcomp_dataset_id_unique');
            $table->index(['dataset_id', 'class_code'], 'refcomp_class_idx');
        });

        DB::statement('ALTER TABLE reference_compositions ADD CONSTRAINT chk_refcomp_cost CHECK (total_cost >= 0)');
        DB::statement('ALTER TABLE reference_compositions ADD FULLTEXT INDEX refcomp_description_ft (description)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_compositions');
    }
};
