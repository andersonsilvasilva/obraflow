<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versões do orçamento (D-08). Com `locked_at` preenchido (aprovação) a versão e seus itens ficam somente leitura —
 * garantido pela APLICAÇÃO (D-P31: sem triggers). Alterar orçamento aprovado = nova versão copiada.
 * `totals_snapshot` = totais recalculados pelo servidor (D-P33). Desconto global sobre o preço de venda (D-P02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_versions', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('budget_id');
            $table->unsignedInteger('version_number');
            $table->string('label', 100)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('social_charges_regime', 20);
            $table->decimal('discount_percent', 7, 4)->default(0);
            $table->json('totals_snapshot')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            RS::tenantKey($table, 'budver_tenant_id_unique');
            $table->unique(['tenant_id', 'budget_id', 'version_number'], 'budver_number_unique');
            $table->unique(['tenant_id', 'budget_id', 'id'], 'budver_budget_id_unique'); // alvo da FK de budgets
            RS::ownerFk($table, 'budget_id', 'budgets', 'budver_budget_fk');
            $table->foreign(['tenant_id', 'created_by'], 'budver_creator_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE budget_versions ADD CONSTRAINT chk_budver_number CHECK (version_number >= 1)');
        DB::statement("ALTER TABLE budget_versions ADD CONSTRAINT chk_budver_status CHECK (status IN ('draft','in_review','approved','rejected','superseded'))");
        DB::statement("ALTER TABLE budget_versions ADD CONSTRAINT chk_budver_regime CHECK (social_charges_regime IN ('with_relief','without_relief','not_applicable'))");
        DB::statement('ALTER TABLE budget_versions ADD CONSTRAINT chk_budver_discount CHECK (discount_percent >= 0 AND discount_percent < 100)');
        DB::statement("ALTER TABLE budget_versions ADD CONSTRAINT chk_budver_lock CHECK (status NOT IN ('approved','superseded') OR locked_at IS NOT NULL)");

        // Versão corrente pertence ao próprio orçamento (e ao mesmo tenant).
        Schema::table('budgets', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'id', 'current_version_id'], 'bud_current_version_fk')
                ->references(['tenant_id', 'budget_id', 'id'])->on('budget_versions')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('budgets', fn (Blueprint $table) => $table->dropForeign('bud_current_version_fk'));
        Schema::dropIfExists('budget_versions');
    }
};
