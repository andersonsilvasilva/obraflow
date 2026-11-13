<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo documento ↔ cadastro. ÚNICA tabela polimórfica da Fase 2 (D-P12): o banco garante o documento
 * (FK composta) e o tipo (CHECK, aliases do morph map); a existência do dono NO MESMO TENANT é garantida
 * pela aplicação (serviço de vínculo) e coberta por testes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_links', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('document_id');
            $table->string('linkable_type', 30);
            $table->unsignedBigInteger('linkable_id');
            $table->timestamps();

            RS::tenantKey($table, 'doclink_tenant_id_unique');
            RS::ownerFk($table, 'document_id', 'documents', 'doclink_document_fk');
            $table->unique(['tenant_id', 'document_id', 'linkable_type', 'linkable_id'], 'doclink_unique');
            $table->index(['tenant_id', 'linkable_type', 'linkable_id'], 'doclink_owner_idx');
        });

        DB::statement("ALTER TABLE document_links ADD CONSTRAINT chk_doclink_type CHECK (linkable_type IN ('company','company_branch','customer','supplier'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_links');
    }
};
