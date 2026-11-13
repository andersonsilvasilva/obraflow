<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documento (metadados; o arquivo fica no disco privado / S3 — D-P15).
 * A FK de current_version_id é criada na migration de document_versions (dependência circular)
 * e garante que a versão corrente é DESTE documento.
 * created_by tem vínculo no tenant (FK composta → tenant_users), como os convites da Fase 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            RS::base($table);
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('title', 200);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('visibility', 20)->default('private');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            RS::tenantKey($table, 'documents_tenant_id_unique');
            RS::ownerFk($table, 'category_id', 'document_categories', 'documents_category_fk');
            $table->foreign(['tenant_id', 'created_by'], 'documents_creator_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['tenant_id', 'status'], 'documents_tenant_status_idx');
        });

        DB::statement("ALTER TABLE documents ADD CONSTRAINT chk_documents_status CHECK (status IN ('active','archived'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT chk_documents_visibility CHECK (visibility IN ('private','restricted'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
