<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/** Endereço da obra (padrão D-P12: tabela dedicada, FK composta real, um endereço principal). */
return new class extends Migration
{
    public function up(): void
    {
        RS::createAddresses('project_addresses', 'project_id', 'projects', 'prjaddr');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_addresses');
    }
};
