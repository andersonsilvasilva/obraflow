<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration de AJUSTE (não cria tabela): `document_links` passa a aceitar `contract` e `contract_addendum` (D-P55 — anexos do contrato pelos
 * documentos da Fase 2). O tipo `measurement` entra com a parte 5B. O down() volta ao CHECK anterior e falha, de
 * propósito, se já houver vínculos com contrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE document_links DROP CHECK chk_doclink_type');
        DB::statement("ALTER TABLE document_links ADD CONSTRAINT chk_doclink_type CHECK (linkable_type IN ('company','company_branch','customer','supplier','employee','equipment','project','contract','contract_addendum'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE document_links DROP CHECK chk_doclink_type');
        DB::statement("ALTER TABLE document_links ADD CONSTRAINT chk_doclink_type CHECK (linkable_type IN ('company','company_branch','customer','supplier','employee','equipment','project'))");
    }
};
