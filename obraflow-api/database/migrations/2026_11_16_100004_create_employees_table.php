<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Funcionários (empregado ≠ usuário, D-11). Dados pessoais mínimos (D-P21): sem RG, sexo, nascimento
 * nem dados bancários. CPF opcional, só dígitos, único entre os ativos da empresa.
 * `user_id` liga a um usuário MEMBRO do tenant: FK composta → tenant_users (tenant_id, user_id).
 * UNIQUE (tenant_id, company_id, id) permite às obras exigir responsável técnico da MESMA empresa.
 * "Responsável ≠ o próprio funcionário" é regra da aplicação (CHECK não pode usar coluna AUTO_INCREMENT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            RS::base($table);
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('job_title_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('supervisor_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('registration_number', 30)->nullable();
            $table->string('name', 200);
            $table->char('document_number', 11)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('labor_type', 10)->nullable();
            $table->string('contract_type', 12)->nullable();
            $table->date('hired_at')->nullable();
            $table->date('terminated_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            RS::softDeleteWithMarker($table);

            RS::tenantKey($table, 'emp_tenant_id_unique');
            $table->unique(['tenant_id', 'company_id', 'id'], 'emp_tenant_company_id_unique');
            RS::ownerFk($table, 'company_id', 'companies', 'emp_company_fk');
            RS::ownerFk($table, 'job_title_id', 'job_titles', 'emp_job_title_fk');
            RS::ownerFk($table, 'department_id', 'departments', 'emp_department_fk');
            RS::ownerFk($table, 'supervisor_id', 'employees', 'emp_supervisor_fk');
            $table->foreign(['tenant_id', 'user_id'], 'emp_user_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['tenant_id', 'company_id', 'registration_number', 'active_marker'], 'emp_registration_active');
            $table->unique(['tenant_id', 'company_id', 'document_number', 'active_marker'], 'emp_document_active');
            $table->unique(['tenant_id', 'user_id', 'active_marker'], 'emp_user_active');
        });

        RS::statusCheck('employees');
        DB::statement("ALTER TABLE employees ADD CONSTRAINT chk_emp_doc CHECK (document_number IS NULL OR REGEXP_LIKE(document_number, '^[0-9]{11}$'))");
        DB::statement("ALTER TABLE employees ADD CONSTRAINT chk_emp_labor CHECK (labor_type IS NULL OR labor_type IN ('direct','indirect'))");
        DB::statement("ALTER TABLE employees ADD CONSTRAINT chk_emp_contract CHECK (contract_type IS NULL OR contract_type IN ('monthly','hourly','piecework','daily'))");
        DB::statement('ALTER TABLE employees ADD CONSTRAINT chk_emp_dates CHECK (terminated_at IS NULL OR hired_at IS NULL OR terminated_at >= hired_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
