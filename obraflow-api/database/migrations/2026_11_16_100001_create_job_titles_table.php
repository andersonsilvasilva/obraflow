<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cargos/funções por tenant (D-P20). Sem preço: custo de mão de obra é da Fase 4. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_titles', function (Blueprint $table) {
            RS::base($table);
            $table->string('name', 100);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            RS::tenantKey($table, 'jobt_tenant_id_unique');
            $table->unique(['tenant_id', 'name'], 'jobt_tenant_name_unique');
        });

        RS::statusCheck('job_titles');
    }

    public function down(): void
    {
        Schema::dropIfExists('job_titles');
    }
};
