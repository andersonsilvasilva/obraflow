<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Categorias de documentos (árvore por tenant). Pai é do mesmo tenant (FK composta auto-referente). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->timestamps();

            RS::tenantKey($table, 'doccat_tenant_id_unique');
            $table->unique(['tenant_id', 'code'], 'doccat_tenant_code_unique');
            RS::ownerFk($table, 'parent_id', 'document_categories', 'doccat_parent_fk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_categories');
    }
};
