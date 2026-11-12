<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo usuário ↔ tenant. UNIQUE (tenant_id, user_id) é o alvo das FKs compostas de
 * model_has_roles, model_has_permissions, personal_access_tokens e user_invitations.
 * O vínculo só nasce na aceitação do convite (ou no provisionamento); "invited" não existe aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('membership_status', 20)->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id'], 'tenant_users_tenant_user_unique');
            $table->index(['user_id', 'membership_status'], 'tenant_users_user_status_idx');
        });

        DB::statement("ALTER TABLE tenant_users ADD CONSTRAINT chk_tu_status CHECK (membership_status IN ('active','suspended','removed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};
