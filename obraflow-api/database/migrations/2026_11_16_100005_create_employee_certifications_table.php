<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Certificações/treinamentos do funcionário (ex.: NR), com vencimento e comprovante opcional (documento da Fase 2). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_certifications', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('employee_id');
            $table->string('name', 150);
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->timestamps();

            RS::tenantKey($table, 'empcert_tenant_id_unique');
            RS::ownerFk($table, 'employee_id', 'employees', 'empcert_employee_fk');
            RS::ownerFk($table, 'document_id', 'documents', 'empcert_document_fk');
            $table->index(['tenant_id', 'expires_at'], 'empcert_expires_idx');
        });

        DB::statement('ALTER TABLE employee_certifications ADD CONSTRAINT chk_empcert_dates CHECK (expires_at IS NULL OR issued_at IS NULL OR expires_at >= issued_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_certifications');
    }
};
