<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fornecedores (D-30: aceita pessoa física, ex.: autônomo). Pertence a uma empresa do tenant (D-P17). Documento só com dígitos: CPF (11) para pessoa, CNPJ (14) para empresa. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            RS::base($table);
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('type', 10);
            $table->string('document_number', 14)->charset('ascii')->collation('ascii_bin');
            $table->string('legal_name', 200);
            $table->string('trade_name', 200)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            RS::softDeleteWithMarker($table);

            RS::tenantKey($table, 'suppliers_tenant_id_unique');
            RS::ownerFk($table, 'company_id', 'companies', 'suppliers_company_fk');
            $table->unique(['tenant_id', 'company_id', 'document_number', 'active_marker'], 'suppliers_company_doc_active');
        });

        RS::statusCheck('suppliers');
        RS::partyDocumentCheck('suppliers', 'suppliers');
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
