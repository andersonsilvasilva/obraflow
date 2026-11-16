<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-P19: documentos de funcionário, equipamento e obra usam document_links (sem tabelas dedicadas).
 * Migration de AJUSTE (não cria tabela): substitui o CHECK de linkable_type. A migration da Fase 2 não é alterada.
 * O down() volta ao CHECK original e falha, de propósito, se já houver vínculos com os tipos novos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE document_links DROP CHECK chk_doclink_type');
        DB::statement("ALTER TABLE document_links ADD CONSTRAINT chk_doclink_type CHECK (linkable_type IN ('company','company_branch','customer','supplier','employee','equipment','project'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE document_links DROP CHECK chk_doclink_type');
        DB::statement("ALTER TABLE document_links ADD CONSTRAINT chk_doclink_type CHECK (linkable_type IN ('company','company_branch','customer','supplier'))");
    }
};
