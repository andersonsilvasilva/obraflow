<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users é GLOBAL: não possui tenant_id (D-02). O vínculo com tenants é tenant_users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150);
            // sem diferenciar caixa, diferenciando acento (FASE1_MODELO §3.1)
            $table->string('email', 190)->collation('utf8mb4_0900_as_ci')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('status', 20)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_status CHECK (status IN ('active','blocked'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
