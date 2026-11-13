<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        RS::createContacts('customer_contacts', 'customer_id', 'customers', 'cucont');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contacts');
    }
};
