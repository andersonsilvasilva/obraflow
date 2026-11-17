<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Insumos de uma base. `kind` vem da "Macro-Classe" do relatório de famílias da fonte (nunca deduzido);
 * NULL quando a fonte não informa. UNIQUE (dataset_id, id) é alvo das FKs dos itens de composição.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('reference_datasets')->restrictOnDelete()->restrictOnUpdate();
            $table->string('code', 20);
            $table->text('description');
            $table->foreignId('unit_id')->constrained('reference_units')->restrictOnDelete()->restrictOnUpdate();
            $table->string('kind', 20)->nullable();
            $table->string('family_class', 60)->nullable(); // Macro-Classe como publicada
            $table->timestamps();

            $table->unique(['dataset_id', 'code'], 'refres_dataset_code_unique');
            $table->unique(['dataset_id', 'id'], 'refres_dataset_id_unique');
        });

        DB::statement("ALTER TABLE reference_resources ADD CONSTRAINT chk_refres_kind CHECK (kind IS NULL OR kind IN ('material','labor','equipment','service'))");
        DB::statement('ALTER TABLE reference_resources ADD FULLTEXT INDEX refres_description_ft (description)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_resources');
    }
};
