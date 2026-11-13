<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versões de documento — append-only (sem updated_at; o modelo bloqueia update/delete).
 * storage_key é a chave do objeto no disco; NUNCA há URL pública permanente (URL assinada de curta duração).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('version_number');
            $table->string('storage_disk', 30);
            $table->string('storage_key', 500)->charset('ascii')->collation('ascii_bin');
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->charset('ascii')->collation('ascii_bin');
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamp('created_at')->nullable();

            RS::tenantKey($table, 'docver_tenant_id_unique');
            $table->unique(['tenant_id', 'document_id', 'version_number'], 'docver_doc_version_unique');
            $table->unique(['tenant_id', 'document_id', 'id'], 'docver_doc_id_unique'); // alvo da FK de documents
            $table->unique(['storage_disk', 'storage_key'], 'docver_storage_unique');
            RS::ownerFk($table, 'document_id', 'documents', 'docver_document_fk');
            $table->foreign(['tenant_id', 'uploaded_by'], 'docver_uploader_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT chk_docver_number CHECK (version_number >= 1)');
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT chk_docver_sha256 CHECK (REGEXP_LIKE(sha256, '^[0-9a-f]{64}$', 'c'))");

        // Versão corrente pertence ao próprio documento (e ao mesmo tenant).
        Schema::table('documents', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'id', 'current_version_id'], 'documents_current_version_fk')
                ->references(['tenant_id', 'document_id', 'id'])->on('document_versions')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign('documents_current_version_fk');
        });
        Schema::dropIfExists('document_versions');
    }
};
