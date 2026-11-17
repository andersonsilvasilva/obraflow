<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Histórico de status do contrato — append-only (só o ContractWorkflow cria). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_status_history', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('contract_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->unsignedBigInteger('changed_by');
            $table->string('reason', 1000)->nullable();
            $table->timestamp('changed_at', 6)->useCurrent();

            RS::tenantKey($table, 'ctrsh_tenant_id_unique');
            RS::ownerFk($table, 'contract_id', 'contracts', 'ctrsh_contract_fk');
            $table->foreign(['tenant_id', 'changed_by'], 'ctrsh_changer_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['tenant_id', 'contract_id', 'changed_at'], 'ctrsh_contract_idx');
        });

        $states = "'draft','active','suspended','completed','canceled'";
        DB::statement("ALTER TABLE contract_status_history ADD CONSTRAINT chk_ctrsh_to CHECK (to_status IN ({$states}))");
        DB::statement("ALTER TABLE contract_status_history ADD CONSTRAINT chk_ctrsh_from CHECK (from_status IS NULL OR from_status IN ({$states}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_status_history');
    }
};
