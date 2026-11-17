<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rastreio de cada ARQUIVO importado numa base (insumos, composições, famílias): nome, SHA-256 e contagens.
 * Uma base recebe vários arquivos; o mesmo arquivo (hash) não é importado duas vezes (idempotência, D-P30).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('reference_datasets')->restrictOnDelete()->restrictOnUpdate();
            $table->string('kind', 20);
            $table->string('file_name', 255);
            $table->char('file_sha256', 64)->charset('ascii')->collation('ascii_bin');
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            $table->timestamp('imported_at')->useCurrent();
            $table->timestamps();

            $table->unique(['dataset_id', 'kind'], 'refimp_dataset_kind_unique');
        });

        DB::statement("ALTER TABLE reference_imports ADD CONSTRAINT chk_refimp_kind CHECK (kind IN ('resources','compositions','families'))");
        DB::statement("ALTER TABLE reference_imports ADD CONSTRAINT chk_refimp_sha CHECK (REGEXP_LIKE(file_sha256, '^[0-9a-f]{64}$'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_imports');
    }
};
