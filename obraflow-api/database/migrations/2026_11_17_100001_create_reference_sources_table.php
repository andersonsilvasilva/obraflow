<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fontes de referência técnica (ex.: SINAPI). GLOBAL (D-P29): sem tenant_id, somente leitura para os tenants;
 * gravada apenas pelo comando de importação da plataforma (D-P30).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('publisher', 150)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_sources');
    }
};
