<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Pessoas jurídicas administradas pelo tenant. CNPJ só com dígitos; único entre as ativas do tenant (D-P13). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            RS::base($table);
            $table->uuid('uuid')->unique();
            $table->string('legal_name', 200);
            $table->string('trade_name', 200)->nullable();
            $table->char('document_number', 14)->charset('ascii')->collation('ascii_bin');
            $table->string('state_registration', 30)->nullable();
            $table->string('municipal_registration', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            RS::softDeleteWithMarker($table);

            RS::tenantKey($table, 'companies_tenant_id_unique');
            $table->unique(['tenant_id', 'document_number', 'active_marker'], 'companies_tenant_cnpj_active');
        });

        RS::statusCheck('companies');
        DB::statement("ALTER TABLE companies ADD CONSTRAINT chk_companies_cnpj CHECK (REGEXP_LIKE(document_number, '^[0-9]{14}$'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
