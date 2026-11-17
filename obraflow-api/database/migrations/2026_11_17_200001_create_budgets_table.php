<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orçamentos (Fase 4B). Pertence a uma empresa do tenant; obra e cliente opcionais (mesma empresa — aplicação).
 * Base de referência opcional (global, D-P29): o orçamento COPIA o que usa (D-08), a FK só registra a origem.
 * Regime de encargos gravado no orçamento E em cada versão (D-P03). Dono é membro do tenant.
 * A FK de current_version_id é criada na migration de budget_versions (dependência circular).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            RS::base($table);
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('code', 30);
            $table->string('title', 200);
            $table->string('mode', 20);
            $table->foreignId('reference_dataset_id')->nullable()->constrained('reference_datasets')->restrictOnDelete()->restrictOnUpdate();
            $table->char('state', 2)->nullable();
            $table->string('social_charges_regime', 20);
            $table->date('reference_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('owner_id');
            $table->text('notes')->nullable();
            $table->timestamps();
            RS::softDeleteWithMarker($table);

            RS::tenantKey($table, 'bud_tenant_id_unique');
            RS::ownerFk($table, 'company_id', 'companies', 'bud_company_fk');
            RS::ownerFk($table, 'project_id', 'projects', 'bud_project_fk');
            RS::ownerFk($table, 'customer_id', 'customers', 'bud_customer_fk');
            $table->foreign(['tenant_id', 'owner_id'], 'bud_owner_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['tenant_id', 'code', 'active_marker'], 'bud_code_active');
            $table->index(['tenant_id', 'status'], 'bud_status_idx');
        });

        DB::statement("ALTER TABLE budgets ADD CONSTRAINT chk_bud_mode CHECK (mode IN ('customer','manual','reference'))");
        DB::statement("ALTER TABLE budgets ADD CONSTRAINT chk_bud_regime CHECK (social_charges_regime IN ('with_relief','without_relief','not_applicable'))");
        DB::statement("ALTER TABLE budgets ADD CONSTRAINT chk_bud_status CHECK (status IN ('draft','in_review','approved','rejected','archived'))");
        DB::statement("ALTER TABLE budgets ADD CONSTRAINT chk_bud_state CHECK (state IS NULL OR REGEXP_LIKE(state, '^[A-Z]{2}$', 'c'))");
        DB::statement("ALTER TABLE budgets ADD CONSTRAINT chk_bud_reference CHECK (mode <> 'reference' OR reference_dataset_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
