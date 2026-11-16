<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Histórico de status da obra — append-only (sem updated_at; o modelo recusa update/delete). Autor é membro do tenant. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_status_history', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('project_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->unsignedBigInteger('changed_by');
            $table->string('reason', 500)->nullable();
            $table->timestamp('changed_at', 6)->useCurrent();

            RS::tenantKey($table, 'prjsh_tenant_id_unique');
            RS::ownerFk($table, 'project_id', 'projects', 'prjsh_project_fk');
            $table->foreign(['tenant_id', 'changed_by'], 'prjsh_changer_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->index(['tenant_id', 'project_id', 'changed_at'], 'prjsh_project_idx');
        });

        $states = "'planning','in_progress','paused','delayed','completed','canceled'";
        DB::statement("ALTER TABLE project_status_history ADD CONSTRAINT chk_prjsh_to CHECK (to_status IN ({$states}))");
        DB::statement("ALTER TABLE project_status_history ADD CONSTRAINT chk_prjsh_from CHECK (from_status IS NULL OR from_status IN ({$states}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('project_status_history');
    }
};
