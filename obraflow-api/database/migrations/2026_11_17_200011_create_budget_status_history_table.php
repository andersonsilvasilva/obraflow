<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Histórico de status do orçamento/versão — append-only. Versão do PRÓPRIO orçamento (FK composta). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_status_history', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('budget_version_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->unsignedBigInteger('changed_by');
            $table->string('reason', 1000)->nullable();
            $table->timestamp('changed_at', 6)->useCurrent();

            RS::tenantKey($table, 'budsh_tenant_id_unique');
            RS::ownerFk($table, 'budget_id', 'budgets', 'budsh_budget_fk');
            $table->foreign(['tenant_id', 'budget_id', 'budget_version_id'], 'budsh_version_fk')
                ->references(['tenant_id', 'budget_id', 'id'])->on('budget_versions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tenant_id', 'changed_by'], 'budsh_changer_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['tenant_id', 'budget_id', 'changed_at'], 'budsh_budget_idx');
        });

        $states = "'draft','in_review','approved','rejected','superseded'";
        DB::statement("ALTER TABLE budget_status_history ADD CONSTRAINT chk_budsh_to CHECK (to_status IN ({$states}))");
        DB::statement("ALTER TABLE budget_status_history ADD CONSTRAINT chk_budsh_from CHECK (from_status IS NULL OR from_status IN ({$states}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_status_history');
    }
};
