<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-P40: grupos de recursos passam a servir também à mão de obra e aos serviços do catálogo (Fase 4C).
 * Migration de AJUSTE (não cria tabela). O down() volta ao CHECK original e falha se já houver grupos dos novos tipos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE resource_groups DROP CHECK chk_rgrp_kind');
        DB::statement("ALTER TABLE resource_groups ADD CONSTRAINT chk_rgrp_kind CHECK (kind IN ('equipment','material','labor','service'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE resource_groups DROP CHECK chk_rgrp_kind');
        DB::statement("ALTER TABLE resource_groups ADD CONSTRAINT chk_rgrp_kind CHECK (kind IN ('equipment','material'))");
    }
};
