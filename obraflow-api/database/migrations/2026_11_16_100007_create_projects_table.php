<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Obras. Responsável técnico é funcionário (D-P23) da MESMA empresa: FK (tenant_id, company_id, technical_manager_id)
 * → employees (tenant_id, company_id, id). Cliente da mesma empresa é regra da aplicação (customers é tabela homologada
 * da Fase 2 e não tem o índice equivalente). Quem cria é membro do tenant (FK → tenant_users). Progresso informado (D-P27).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            RS::base($table);
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('code', 30);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('technical_manager_id')->nullable();
            $table->decimal('contract_value', 15, 2)->nullable();
            $table->string('status', 20)->default('planning');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->date('start_date')->nullable();
            $table->date('expected_end_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            RS::softDeleteWithMarker($table);

            RS::tenantKey($table, 'proj_tenant_id_unique');
            RS::ownerFk($table, 'company_id', 'companies', 'proj_company_fk');
            RS::ownerFk($table, 'customer_id', 'customers', 'proj_customer_fk');
            $table->foreign(['tenant_id', 'company_id', 'technical_manager_id'], 'proj_manager_fk')
                ->references(['tenant_id', 'company_id', 'id'])->on('employees')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tenant_id', 'created_by'], 'proj_creator_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['tenant_id', 'code', 'active_marker'], 'proj_code_active');
            $table->index(['tenant_id', 'status'], 'proj_status_idx');
        });

        DB::statement("ALTER TABLE projects ADD CONSTRAINT chk_proj_status CHECK (status IN ('planning','in_progress','paused','delayed','completed','canceled'))");
        DB::statement('ALTER TABLE projects ADD CONSTRAINT chk_proj_progress CHECK (progress <= 100)');
        DB::statement('ALTER TABLE projects ADD CONSTRAINT chk_proj_value CHECK (contract_value IS NULL OR contract_value >= 0)');
        DB::statement('ALTER TABLE projects ADD CONSTRAINT chk_proj_expected CHECK (expected_end_date IS NULL OR start_date IS NULL OR expected_end_date >= start_date)');
        DB::statement('ALTER TABLE projects ADD CONSTRAINT chk_proj_actual CHECK (actual_end_date IS NULL OR start_date IS NULL OR actual_end_date >= start_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
