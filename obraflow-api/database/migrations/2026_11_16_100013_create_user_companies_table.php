<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo usuário ↔ empresa (D-P16/D-P25). Criada SEM efeito de bloqueio: ligar a restrição por empresa
 * exige decisão própria. Usuário membro do tenant (FK → tenant_users), empresa do mesmo tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_companies', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('company_id');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            RS::tenantKey($table, 'ucomp_tenant_id_unique');
            $table->unique(['tenant_id', 'user_id', 'company_id'], 'ucomp_user_company_unique');
            RS::ownerFk($table, 'company_id', 'companies', 'ucomp_company_fk');
            $table->foreign(['tenant_id', 'user_id'], 'ucomp_user_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')
                ->restrictOnDelete()->restrictOnUpdate();
        });

        RS::statusCheck('user_companies');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_companies');
    }
};
