<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration de AJUSTE: encarregado da obra (A-46, pedido do responsável — painel de obras como o do Brickup).
 * Funcionário da MESMA empresa da obra, garantido pelo banco como o responsável técnico (FK composta com company_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('foreman_id')->nullable()->after('technical_manager_id');
            $table->foreign(['tenant_id', 'company_id', 'foreman_id'], 'proj_foreman_fk')
                ->references(['tenant_id', 'company_id', 'id'])->on('employees')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign('proj_foreman_fk');
            $table->dropColumn('foreman_id');
        });
    }
};
