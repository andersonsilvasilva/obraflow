<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Equipe da obra: funcionários vinculados com função na obra e período.
 * `open_marker` (1 enquanto ended_on é NULL) garante um único vínculo em aberto por funcionário e obra;
 * vínculos encerrados ficam como histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('employee_id');
            $table->string('role', 60)->nullable();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->tinyInteger('open_marker')->nullable()->virtualAs('IF(ended_on IS NULL, 1, NULL)');
            $table->timestamps();

            RS::tenantKey($table, 'prjmb_tenant_id_unique');
            RS::ownerFk($table, 'project_id', 'projects', 'prjmb_project_fk');
            RS::ownerFk($table, 'employee_id', 'employees', 'prjmb_employee_fk');
            $table->unique(['tenant_id', 'project_id', 'employee_id', 'open_marker'], 'prjmb_one_open');
        });

        DB::statement('ALTER TABLE project_members ADD CONSTRAINT chk_prjmb_dates CHECK (ended_on IS NULL OR ended_on >= started_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
    }
};
