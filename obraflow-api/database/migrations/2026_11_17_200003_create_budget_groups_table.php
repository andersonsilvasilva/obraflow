<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapas do orçamento (EAP, árvore). Etapa-pai da MESMA versão: FK (tenant_id, budget_version_id, parent_id).
 * UNIQUE (tenant_id, budget_version_id, id) é alvo das FKs dos itens e do BDI por etapa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_groups', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('budget_version_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 30);
            $table->string('name', 200);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            RS::tenantKey($table, 'budgrp_tenant_id_unique');
            $table->unique(['tenant_id', 'budget_version_id', 'id'], 'budgrp_version_id_unique');
            RS::ownerFk($table, 'budget_version_id', 'budget_versions', 'budgrp_version_fk');
            $table->foreign(['tenant_id', 'budget_version_id', 'parent_id'], 'budgrp_parent_fk')
                ->references(['tenant_id', 'budget_version_id', 'id'])->on('budget_groups')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->index(['tenant_id', 'budget_version_id', 'sort_order'], 'budgrp_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_groups');
    }
};
