<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Filiais. FK composta → companies: filial de empresa de outro tenant é recusada pelo banco. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_branches', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('company_id');
            $table->string('name', 200);
            $table->char('document_number', 14)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            RS::softDeleteWithMarker($table);

            RS::tenantKey($table, 'branches_tenant_id_unique');
            RS::ownerFk($table, 'company_id', 'companies', 'branches_company_fk');
            $table->unique(['tenant_id', 'document_number', 'active_marker'], 'branches_tenant_cnpj_active');
        });

        RS::statusCheck('company_branches');
        DB::statement("ALTER TABLE company_branches ADD CONSTRAINT chk_branches_cnpj CHECK (document_number IS NULL OR REGEXP_LIKE(document_number, '^[0-9]{14}$'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('company_branches');
    }
};
