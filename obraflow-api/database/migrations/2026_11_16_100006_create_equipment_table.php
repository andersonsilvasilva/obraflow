<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Equipamentos por empresa. Sem custo horário (Fase 4). O grupo deve ser do tipo `equipment`:
 * `group_kind` é fixo por CHECK e entra na FK (tenant_id, group_kind, group_id) → resource_groups (tenant_id, kind, id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            RS::base($table);
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('group_kind', 20)->default('equipment');
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('code', 30)->nullable();
            $table->string('name', 200);
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('kind', 60)->nullable();
            $table->string('condition', 60)->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('main_supplier_id')->nullable();
            $table->string('ownership', 10)->default('owned');
            $table->string('status', 20)->default('available');
            $table->timestamps();
            RS::softDeleteWithMarker($table);

            RS::tenantKey($table, 'equip_tenant_id_unique');
            RS::ownerFk($table, 'company_id', 'companies', 'equip_company_fk');
            RS::ownerFk($table, 'unit_id', 'units_of_measure', 'equip_unit_fk');
            RS::ownerFk($table, 'main_supplier_id', 'suppliers', 'equip_supplier_fk');
            $table->foreign(['tenant_id', 'group_kind', 'group_id'], 'equip_group_fk')
                ->references(['tenant_id', 'kind', 'id'])->on('resource_groups')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['tenant_id', 'company_id', 'code', 'active_marker'], 'equip_code_active');
        });

        DB::statement("ALTER TABLE equipment ADD CONSTRAINT chk_equip_group_kind CHECK (group_kind = 'equipment')");
        DB::statement("ALTER TABLE equipment ADD CONSTRAINT chk_equip_ownership CHECK (ownership IN ('owned','rented'))");
        DB::statement("ALTER TABLE equipment ADD CONSTRAINT chk_equip_status CHECK (status IN ('available','in_use','maintenance','retired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};
