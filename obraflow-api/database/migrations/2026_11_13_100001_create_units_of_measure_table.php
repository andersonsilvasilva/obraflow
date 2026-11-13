<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Unidades de medida POR TENANT (D-P14): tenant_id NOT NULL para a FK composta funcionar. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table) {
            RS::base($table);
            $table->string('code', 10);
            $table->string('name', 60);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            RS::tenantKey($table, 'uom_tenant_id_unique');
            $table->unique(['tenant_id', 'code'], 'uom_tenant_code_unique');
        });

        RS::statusCheck('units_of_measure');
    }

    public function down(): void
    {
        Schema::dropIfExists('units_of_measure');
    }
};
