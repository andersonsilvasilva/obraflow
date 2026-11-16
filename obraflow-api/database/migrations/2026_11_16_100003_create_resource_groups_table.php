<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grupos/subgrupos de recursos (D-P22), compartilhados por equipamentos (Fase 3) e materiais (Fase 4).
 * O subgrupo aponta para o pai pela FK (tenant_id, kind, parent_id): o banco recusa pai de outro tenant
 * ou de outro tipo. `parent_key` (0 na raiz) faz a unicidade do nome valer também entre grupos raiz.
 * Profundidade máxima (2 níveis) é regra da aplicação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_groups', function (Blueprint $table) {
            RS::base($table);
            $table->string('kind', 20);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 100);
            $table->unsignedBigInteger('parent_key')->virtualAs('IFNULL(parent_id, 0)');
            $table->timestamps();

            RS::tenantKey($table, 'rgrp_tenant_id_unique');
            $table->unique(['tenant_id', 'kind', 'id'], 'rgrp_tenant_kind_id_unique');
            $table->unique(['tenant_id', 'kind', 'parent_key', 'name'], 'rgrp_name_unique');
            $table->foreign(['tenant_id', 'kind', 'parent_id'], 'rgrp_parent_fk')
                ->references(['tenant_id', 'kind', 'id'])->on('resource_groups')
                ->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE resource_groups ADD CONSTRAINT chk_rgrp_kind CHECK (kind IN ('equipment','material'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_groups');
    }
};
