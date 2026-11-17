<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-P41: item de orçamento copiado do catálogo da organização ganha a origem `catalog` (cópia autossuficiente, D-08).
 * Migration de AJUSTE (não cria tabela). O down() falha se já houver itens com a origem nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE budget_items DROP CHECK chk_buditem_source');
        DB::statement("ALTER TABLE budget_items ADD CONSTRAINT chk_buditem_source CHECK (source IN ('official','custom','manual','catalog'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE budget_items DROP CHECK chk_buditem_source');
        DB::statement("ALTER TABLE budget_items ADD CONSTRAINT chk_buditem_source CHECK (source IN ('official','custom','manual'))");
    }
};
