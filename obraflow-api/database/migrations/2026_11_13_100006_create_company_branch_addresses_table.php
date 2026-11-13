<?php

use App\Modules\Registry\Database\RegistrySchema as RS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        RS::createAddresses('company_branch_addresses', 'company_branch_id', 'company_branches', 'braddr');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_branch_addresses');
    }
};
