<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        RS::createAddresses('supplier_addresses', 'supplier_id', 'suppliers', 'suaddr');
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_addresses');
    }
};
