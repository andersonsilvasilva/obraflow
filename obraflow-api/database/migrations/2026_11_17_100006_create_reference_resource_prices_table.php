<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preço do insumo na base (a base já fixa competência, UF e regime: um preço por insumo).
 * `price_origin` como publicado pela fonte (ex.: CR = coletado, AS = atribuído São Paulo). Valor nunca editado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_resource_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->unique()->constrained('reference_resources')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('price', 15, 2);
            $table->string('price_origin', 40)->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE reference_resource_prices ADD CONSTRAINT chk_refprice_positive CHECK (price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_resource_prices');
    }
};
