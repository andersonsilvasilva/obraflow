<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de insumos da ORGANIZAÇÃO (Fase 4C, D-P37): materiais, mão de obra, equipamentos (custo — D-P38) e
 * serviços, com preço da empresa. Grupo do MESMO tipo: FK (tenant_id, kind, group_id) → resource_groups.
 * Cargo só para mão de obra; equipamento da Fase 3 só para equipamento (CHECK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            RS::base($table);
            $table->uuid('uuid')->unique();
            $table->string('kind', 20);
            $table->string('code', 30)->nullable();
            $table->string('name', 200);
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedBigInteger('main_supplier_id')->nullable();
            $table->unsignedBigInteger('job_title_id')->nullable();
            $table->unsignedBigInteger('equipment_id')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->date('price_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            RS::softDeleteWithMarker($table);

            RS::tenantKey($table, 'res_tenant_id_unique');
            RS::ownerFk($table, 'unit_id', 'units_of_measure', 'res_unit_fk');
            RS::ownerFk($table, 'main_supplier_id', 'suppliers', 'res_supplier_fk');
            RS::ownerFk($table, 'job_title_id', 'job_titles', 'res_job_title_fk');
            RS::ownerFk($table, 'equipment_id', 'equipment', 'res_equipment_fk');
            $table->foreign(['tenant_id', 'kind', 'group_id'], 'res_group_fk')
                ->references(['tenant_id', 'kind', 'id'])->on('resource_groups')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['tenant_id', 'code', 'active_marker'], 'res_code_active');
            $table->index(['tenant_id', 'kind', 'status'], 'res_kind_idx');
        });

        RS::statusCheck('resources');
        DB::statement("ALTER TABLE resources ADD CONSTRAINT chk_res_kind CHECK (kind IN ('material','labor','equipment','service'))");
        DB::statement('ALTER TABLE resources ADD CONSTRAINT chk_res_price CHECK (price IS NULL OR price >= 0)');
        DB::statement("ALTER TABLE resources ADD CONSTRAINT chk_res_links CHECK ((job_title_id IS NULL OR kind = 'labor') AND (equipment_id IS NULL OR kind = 'equipment'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
