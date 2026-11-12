<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Catálogo GLOBAL de permissões (Spatie). Instalado por comando idempotente, não por seeder de demonstração. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 125);
            $table->string('guard_name', 125);
            $table->string('module', 60)->nullable(); // agrupamento na UI
            $table->timestamps();

            $table->unique(['name', 'guard_name'], 'permissions_name_guard_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
