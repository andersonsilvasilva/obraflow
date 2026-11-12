<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete()->restrictOnUpdate();
            $table->string('setting_key', 100); // "key" é palavra reservada do MySQL
            $table->json('setting_value');
            $table->timestamps();

            $table->unique(['tenant_id', 'setting_key'], 'ts_tenant_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
