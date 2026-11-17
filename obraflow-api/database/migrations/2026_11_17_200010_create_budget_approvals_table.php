<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decisões sobre versões (D-P34) — append-only. `totals_hash` = SHA-256 dos totais no momento da decisão:
 * prova que o valor aprovado é o que está na versão bloqueada. Quem decide é membro do tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_approvals', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('budget_version_id');
            $table->string('decision', 10);
            $table->unsignedBigInteger('decided_by');
            $table->timestamp('decided_at', 6)->useCurrent();
            $table->string('comment', 1000)->nullable();
            $table->char('totals_hash', 64)->charset('ascii')->collation('ascii_bin');

            RS::tenantKey($table, 'budappr_tenant_id_unique');
            RS::ownerFk($table, 'budget_version_id', 'budget_versions', 'budappr_version_fk');
            $table->foreign(['tenant_id', 'decided_by'], 'budappr_decider_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE budget_approvals ADD CONSTRAINT chk_budappr_decision CHECK (decision IN ('approved','rejected'))");
        DB::statement("ALTER TABLE budget_approvals ADD CONSTRAINT chk_budappr_hash CHECK (REGEXP_LIKE(totals_hash, '^[0-9a-f]{64}$'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_approvals');
    }
};
