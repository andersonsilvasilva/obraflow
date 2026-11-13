<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        RS::createAddresses('company_addresses', 'company_id', 'companies', 'coaddr');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_addresses');
    }
};
