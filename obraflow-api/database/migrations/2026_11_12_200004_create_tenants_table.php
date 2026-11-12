<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150);
            $table->string('slug', 80)->unique(); // slug de tenant removido continua reservado
            $table->string('legal_name', 200)->nullable();
            $table->string('document_number', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->string('locale', 10)->default('pt_BR');
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("ALTER TABLE tenants ADD CONSTRAINT chk_tenants_status CHECK (status IN ('trial','active','suspended','canceled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
