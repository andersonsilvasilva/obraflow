<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sem uso até a adoção de subdomínio (D-P01, pré-seleção futura). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete()->restrictOnUpdate();
            $table->string('domain', 190)->unique(); // minúsculas
            $table->boolean('is_primary')->default(false);
            // Coluna gerada: no máximo um domínio primário por tenant (NULL não conflita no UNIQUE)
            $table->tinyInteger('primary_marker')->nullable()->virtualAs('IF(is_primary = 1, 1, NULL)');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'primary_marker'], 'td_one_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
