<?php

namespace App\Modules\Registry\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blocos de esquema compartilhados pelas migrations da Fase 2 (FASE2_MODELO.md).
 * Padrões herdados da Fase 1: FK compostas (tenant_id, x_id), todas RESTRICT, sem triggers,
 * UNIQUE (tenant_id, id) como alvo de FK, CHECK para domínios fechados.
 */
final class RegistrySchema
{
    /** Colunas iniciais de toda tabela operacional. */
    public static function base(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete()->restrictOnUpdate();
    }

    /** UNIQUE (tenant_id, id): alvo das FKs compostas das tabelas filhas. */
    public static function tenantKey(Blueprint $table, string $name): void
    {
        $table->unique(['tenant_id', 'id'], $name);
    }

    /** FK composta (tenant_id, $column) → $parent (tenant_id, id): o banco recusa vínculo entre tenants. */
    public static function ownerFk(Blueprint $table, string $column, string $parent, string $name): void
    {
        $table->foreign(['tenant_id', $column], $name)
            ->references(['tenant_id', 'id'])->on($parent)
            ->restrictOnDelete()->restrictOnUpdate();
    }

    /**
     * Soft delete + coluna gerada `active_marker` (1 quando ativo, NULL quando excluído):
     * em índices UNIQUE, NULL não conflita — o documento excluído pode ser recadastrado (D-P13).
     */
    public static function softDeleteWithMarker(Blueprint $table): void
    {
        $table->softDeletes();
        $table->tinyInteger('active_marker')->nullable()->virtualAs('IF(deleted_at IS NULL, 1, NULL)');
    }

    public static function statusCheck(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chk_{$table}_status CHECK (status IN ('active','inactive'))");
    }

    /** Cliente/fornecedor: `type` person exige CPF (11 dígitos), company exige CNPJ (14); documento NULL é aceito. */
    public static function partyDocumentCheck(string $table, string $prefix): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chk_{$prefix}_type CHECK (type IN ('person','company'))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chk_{$prefix}_doc CHECK (document_number IS NULL
            OR (type = 'person' AND REGEXP_LIKE(document_number, '^[0-9]{11}$'))
            OR (type = 'company' AND REGEXP_LIKE(document_number, '^[0-9]{14}$')))");
    }

    /** Endereços dedicados a um dono (D-P12): FK composta real; no máximo um endereço `main` por dono. */
    public static function createAddresses(string $table, string $ownerColumn, string $parent, string $prefix): void
    {
        Schema::create($table, function (Blueprint $t) use ($ownerColumn, $parent, $prefix) {
            self::base($t);
            $t->unsignedBigInteger($ownerColumn);
            $t->string('kind', 20)->default('main');
            $t->string('street', 200);
            $t->string('number', 20)->nullable();
            $t->string('complement', 100)->nullable();
            $t->string('district', 100)->nullable();
            $t->string('city', 100);
            $t->char('state', 2);
            $t->char('postal_code', 8)->nullable(); // só dígitos
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->tinyInteger('main_marker')->nullable()->virtualAs("IF(kind = 'main', 1, NULL)");
            $t->timestamps();

            self::tenantKey($t, "{$prefix}_tenant_id_unique");
            self::ownerFk($t, $ownerColumn, $parent, "{$prefix}_owner_fk");
            $t->unique(['tenant_id', $ownerColumn, 'main_marker'], "{$prefix}_one_main");
        });

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chk_{$prefix}_kind CHECK (kind IN ('main','billing','delivery','site'))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chk_{$prefix}_state CHECK (REGEXP_LIKE(state, '^[A-Z]{2}$', 'c'))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chk_{$prefix}_cep CHECK (postal_code IS NULL OR REGEXP_LIKE(postal_code, '^[0-9]{8}$'))");
    }

    /** Contatos dedicados a um dono (D-P12): no máximo um contato principal por dono. */
    public static function createContacts(string $table, string $ownerColumn, string $parent, string $prefix): void
    {
        Schema::create($table, function (Blueprint $t) use ($ownerColumn, $parent, $prefix) {
            self::base($t);
            $t->unsignedBigInteger($ownerColumn);
            $t->string('name', 150);
            $t->string('role', 100)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('phone', 30)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->tinyInteger('primary_marker')->nullable()->virtualAs('IF(is_primary = 1, 1, NULL)');
            $t->timestamps();

            self::tenantKey($t, "{$prefix}_tenant_id_unique");
            self::ownerFk($t, $ownerColumn, $parent, "{$prefix}_owner_fk");
            $t->unique(['tenant_id', $ownerColumn, 'primary_marker'], "{$prefix}_one_primary");
        });
    }
}
