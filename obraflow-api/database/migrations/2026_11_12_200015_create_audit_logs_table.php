<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha APPEND-ONLY. Sem updated_at. Imutabilidade: guarda no modelo + privilégios de produção
 * (INSERT/SELECT). Sem triggers (D-22).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete()->restrictOnUpdate(); // NULL = fora de tenant
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('event', 60);
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->binary('ip_address', 16)->nullable(); // VARBINARY(16)
            $table->string('user_agent', 255)->nullable();
            $table->char('request_id', 36)->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['tenant_id', 'created_at'], 'al_tenant_created_idx');
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'al_tenant_entity_idx');
            $table->index(['user_id', 'created_at'], 'al_user_created_idx');
            $table->index(['event', 'created_at'], 'al_event_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
