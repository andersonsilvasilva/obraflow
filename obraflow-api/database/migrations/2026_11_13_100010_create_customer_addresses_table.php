<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        RS::createAddresses('customer_addresses', 'customer_id', 'customers', 'cuaddr');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
