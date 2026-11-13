<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        RS::createContacts('company_contacts', 'company_id', 'companies', 'cocont');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_contacts');
    }
};
