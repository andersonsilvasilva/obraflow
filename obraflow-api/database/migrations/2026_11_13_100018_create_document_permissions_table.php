<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permissões por documento (visibilidade `restricted`). subject_type `project` só entra na Fase 3
 * (obras não existem ainda); hoje o CHECK aceita apenas `user` e `role`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_permissions', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('document_id');
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->string('ability', 20);
            $table->timestamps();

            RS::tenantKey($table, 'docperm_tenant_id_unique');
            RS::ownerFk($table, 'document_id', 'documents', 'docperm_document_fk');
            $table->unique(['tenant_id', 'document_id', 'subject_type', 'subject_id', 'ability'], 'docperm_unique');
        });

        DB::statement("ALTER TABLE document_permissions ADD CONSTRAINT chk_docperm_subject CHECK (subject_type IN ('user','role'))");
        DB::statement("ALTER TABLE document_permissions ADD CONSTRAINT chk_docperm_ability CHECK (ability IN ('view','download','edit','manage'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_permissions');
    }
};
