<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Linhas de base (6A, D-P71): fotografia congelada das datas e durações. Só inserção; até 10 por planejamento
 * (número 1…10, CHECK + UNIQUE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_baselines', function (Blueprint $table) {
            RS::base($table);
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedTinyInteger('number');
            $table->string('name', 100);
            $table->json('snapshot');
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at', 6)->useCurrent();

            RS::tenantKey($table, 'schbl_tenant_id_unique');
            $table->unique(['tenant_id', 'schedule_id', 'number'], 'schbl_number_unique');
            RS::ownerFk($table, 'schedule_id', 'schedules', 'schbl_schedule_fk');
            $table->foreign(['tenant_id', 'created_by'], 'schbl_creator_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE schedule_baselines ADD CONSTRAINT chk_schbl_number CHECK (number BETWEEN 1 AND 10)');
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_baselines');
    }
};
