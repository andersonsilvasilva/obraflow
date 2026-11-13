<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        RS::createContacts('supplier_contacts', 'supplier_id', 'suppliers', 'sucont');
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_contacts');
    }
};
