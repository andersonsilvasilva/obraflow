<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum + tenant (D-05/D-20). tenant_id NULL é aceito pelo banco; a regra "token sem tenant não acessa
 * dados de negócio" é aplicada na borda (middleware). Com tenant, o usuário precisa ter vínculo (FK composta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->index(['tenant_id', 'tokenable_id'], 'pat_tenant_tokenable_idx');

            $table->foreign('tenant_id', 'fk_pat_tenant')->references('id')->on('tenants')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tenant_id', 'tokenable_id'], 'fk_pat_member')->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE personal_access_tokens ADD CONSTRAINT chk_pat_type CHECK (tokenable_type = 'user')");
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
