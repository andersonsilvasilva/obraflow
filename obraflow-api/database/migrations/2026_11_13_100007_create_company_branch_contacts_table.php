<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        RS::createContacts('company_branch_contacts', 'company_branch_id', 'company_branches', 'brcont');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_branch_contacts');
    }
};
