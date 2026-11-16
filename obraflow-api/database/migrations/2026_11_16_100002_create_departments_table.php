<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Setores por tenant (D-P20). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            RS::base($table);
            $table->string('name', 100);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            RS::tenantKey($table, 'dept_tenant_id_unique');
            $table->unique(['tenant_id', 'name'], 'dept_tenant_name_unique');
        });

        RS::statusCheck('departments');
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
