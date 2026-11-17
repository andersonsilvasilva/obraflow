<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contratos (Fase 5A, FASE5_MODELO.md). Sem exclusão (nem lógica): só status e cancelamento (DB_DECISIONS).
 * Partes por FK direta (D-P46): com cliente (`customer`) OU com fornecedor (`supplier`), nunca os dois — CHECK.
 * Sempre dentro de uma obra (A-45: contrato pertence à obra, como no Brickup). Versão de orçamento de origem opcional (D-P47; aprovada e da mesma empresa/obra — aplicação). Valores original
 * e atual são mantidos pelo ContractService (atual = original + aditivos aprovados, D-P48).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            RS::base($table);
            $table->uuid('uuid')->unique();
            $table->string('code', 30);
            $table->string('kind', 20);
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('budget_version_id')->nullable();
            $table->text('object');
            $table->decimal('original_value', 15, 2)->default(0);
            $table->decimal('current_value', 15, 2)->default(0);
            $table->date('starts_at');
            $table->date('original_end_date')->nullable();
            $table->date('current_end_date')->nullable();
            $table->date('signed_at')->nullable();
            $table->decimal('retention_percent', 7, 4)->default(0);
            $table->string('readjustment_rule', 1000)->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            RS::tenantKey($table, 'ctr_tenant_id_unique');
            $table->unique(['tenant_id', 'code'], 'ctr_code_unique');
            RS::ownerFk($table, 'company_id', 'companies', 'ctr_company_fk');
            RS::ownerFk($table, 'project_id', 'projects', 'ctr_project_fk');
            RS::ownerFk($table, 'customer_id', 'customers', 'ctr_customer_fk');
            RS::ownerFk($table, 'supplier_id', 'suppliers', 'ctr_supplier_fk');
            RS::ownerFk($table, 'budget_version_id', 'budget_versions', 'ctr_budget_version_fk');
            $table->foreign(['tenant_id', 'created_by'], 'ctr_creator_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['tenant_id', 'status'], 'ctr_status_idx');
            $table->index(['tenant_id', 'project_id'], 'ctr_project_idx');
        });

        DB::statement("ALTER TABLE contracts ADD CONSTRAINT chk_ctr_kind CHECK (kind IN ('customer','supplier'))");
        DB::statement("ALTER TABLE contracts ADD CONSTRAINT chk_ctr_status CHECK (status IN ('draft','active','suspended','completed','canceled'))");
        DB::statement("ALTER TABLE contracts ADD CONSTRAINT chk_ctr_party CHECK (
            (kind = 'customer' AND customer_id IS NOT NULL AND supplier_id IS NULL)
            OR (kind = 'supplier' AND supplier_id IS NOT NULL AND customer_id IS NULL))");
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT chk_ctr_values CHECK (original_value >= 0 AND current_value >= 0)');
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT chk_ctr_retention CHECK (retention_percent >= 0 AND retention_percent < 100)');
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT chk_ctr_dates CHECK ((original_end_date IS NULL OR original_end_date >= starts_at)
            AND (current_end_date IS NULL OR current_end_date >= starts_at))');
        // ativo, suspenso ou concluído exige assinatura; rascunho e cancelado podem não ter
        DB::statement("ALTER TABLE contracts ADD CONSTRAINT chk_ctr_signed CHECK (status IN ('draft','canceled') OR signed_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
