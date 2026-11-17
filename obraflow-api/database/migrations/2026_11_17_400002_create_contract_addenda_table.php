<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aditivos do contrato (D-P48): valor, prazo, escopo ou misto. Numerados por contrato. Aprovado é imutável
 * (aplicação) e exige assinatura e data de aprovação (CHECK). `value_delta` é calculado pela aplicação a partir
 * das linhas (diferença dos totais dos itens), para que atual = original + Σ aditivos seja exato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_addenda', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('contract_id');
            $table->unsignedSmallInteger('number');
            $table->string('kind', 20);
            $table->decimal('value_delta', 15, 2)->default(0);
            $table->date('new_end_date')->nullable();
            $table->text('description');
            $table->date('signed_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            RS::tenantKey($table, 'ctrad_tenant_id_unique');
            $table->unique(['tenant_id', 'contract_id', 'number'], 'ctrad_number_unique');
            $table->unique(['tenant_id', 'contract_id', 'id'], 'ctrad_contract_id_unique'); // alvo das FKs das linhas/itens
            RS::ownerFk($table, 'contract_id', 'contracts', 'ctrad_contract_fk');
            $table->foreign(['tenant_id', 'created_by'], 'ctrad_creator_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tenant_id', 'approved_by'], 'ctrad_approver_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE contract_addenda ADD CONSTRAINT chk_ctrad_kind CHECK (kind IN ('value','term','scope','mixed'))");
        DB::statement("ALTER TABLE contract_addenda ADD CONSTRAINT chk_ctrad_status CHECK (status IN ('draft','approved','canceled'))");
        DB::statement('ALTER TABLE contract_addenda ADD CONSTRAINT chk_ctrad_number CHECK (number >= 1)');
        DB::statement("ALTER TABLE contract_addenda ADD CONSTRAINT chk_ctrad_approved CHECK (status <> 'approved'
            OR (signed_at IS NOT NULL AND approved_at IS NOT NULL AND approved_by IS NOT NULL))");
        DB::statement("ALTER TABLE contract_addenda ADD CONSTRAINT chk_ctrad_term CHECK (kind <> 'term' OR status <> 'approved' OR new_end_date IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_addenda');
    }
};
