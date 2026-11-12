<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete()->restrictOnUpdate();
            $table->string('email', 190)->collation('utf8mb4_0900_as_ci'); // minúsculas na aplicação
            $table->unsignedBigInteger('invited_by');
            $table->unsignedBigInteger('role_id');
            $table->string('token_hash', 64)->charset('ascii')->collation('ascii_bin')->unique(); // somente o hash
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            // Coluna gerada: no máximo UM convite pendente por e-mail e tenant
            $table->string('pending_email', 190)->collation('utf8mb4_0900_as_ci')->nullable()
                ->virtualAs("IF(status = 'pending', email, NULL)");

            $table->unique(['tenant_id', 'pending_email'], 'ui_one_pending');
            $table->index(['tenant_id', 'invited_by'], 'ui_tenant_invited_by_idx');
            $table->index(['tenant_id', 'role_id'], 'ui_tenant_role_idx');
            $table->index(['tenant_id', 'accepted_user_id'], 'ui_tenant_accepted_idx');

            // convidante e aceitante têm vínculo no tenant; o papel é do mesmo tenant
            $table->foreign(['tenant_id', 'invited_by'], 'fk_ui_inviter')->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tenant_id', 'role_id'], 'fk_ui_role')->references(['tenant_id', 'id'])->on('roles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tenant_id', 'accepted_user_id'], 'fk_ui_accepted')->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE user_invitations ADD CONSTRAINT chk_ui_status CHECK (status IN ('pending','accepted','expired','revoked'))");
        DB::statement("ALTER TABLE user_invitations ADD CONSTRAINT chk_ui_accepted CHECK (status <> 'accepted' OR (accepted_at IS NOT NULL AND accepted_user_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
