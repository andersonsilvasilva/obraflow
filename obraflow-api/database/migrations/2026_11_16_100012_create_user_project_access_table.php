<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Acesso por obra (D-P24). Usuário e quem concedeu são MEMBROS do tenant (FK → tenant_users);
 * a obra é do mesmo tenant. Um nível por usuário e obra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_project_access', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->string('access_level', 10);
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();

            RS::tenantKey($table, 'upa_tenant_id_unique');
            $table->unique(['tenant_id', 'user_id', 'project_id'], 'upa_user_project_unique');
            RS::ownerFk($table, 'project_id', 'projects', 'upa_project_fk');
            $table->foreign(['tenant_id', 'user_id'], 'upa_user_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tenant_id', 'granted_by'], 'upa_granter_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->index(['tenant_id', 'project_id'], 'upa_project_idx');
        });

        DB::statement("ALTER TABLE user_project_access ADD CONSTRAINT chk_upa_level CHECK (access_level IN ('view','edit','manage'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_project_access');
    }
};
