<?php

namespace Tests\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fixtures de baixo nível (sem Eloquent) para os testes de integridade do banco.
 * Os testes de aplicação usam factories/modelos.
 */
final class Fx
{
    public static function tenant(?string $slug = null, string $status = 'active'): int
    {
        $slug ??= 't-'.Str::lower(Str::random(10));

        return (int) DB::table('tenants')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => "Tenant {$slug}", 'slug' => $slug, 'status' => $status,
        ]);
    }

    public static function user(?string $email = null, string $status = 'active'): int
    {
        $email ??= Str::lower(Str::random(10)).'@example.test';

        return (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => "User {$email}", 'email' => $email, 'password' => 'x', 'status' => $status,
        ]);
    }

    public static function member(int $tenant, int $user, string $status = 'active'): int
    {
        return (int) DB::table('tenant_users')->insertGetId([
            'tenant_id' => $tenant, 'user_id' => $user, 'membership_status' => $status,
        ]);
    }

    public static function role(int $tenant, string $name): int
    {
        return (int) DB::table('roles')->insertGetId(['tenant_id' => $tenant, 'name' => $name, 'guard_name' => 'web']);
    }

    public static function assign(int $tenant, int $role, int $user, string $type = 'user'): void
    {
        DB::table('model_has_roles')->insert(['role_id' => $role, 'model_type' => $type, 'model_id' => $user, 'tenant_id' => $tenant]);
    }

    public static function token(?int $tenant, int $user, string $type = 'user'): void
    {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => $type, 'tokenable_id' => $user, 'name' => 't', 'token' => bin2hex(random_bytes(32)), 'tenant_id' => $tenant,
        ]);
    }

    public static function invitation(int $tenant, string $email, int $by, int $role, string $status = 'pending', ?int $acceptedUser = null, bool $acceptedAt = false): void
    {
        DB::table('user_invitations')->insert([
            'tenant_id' => $tenant, 'email' => $email, 'invited_by' => $by, 'role_id' => $role,
            'token_hash' => bin2hex(random_bytes(32)), 'status' => $status, 'expires_at' => '2030-01-01 00:00:00',
            'accepted_at' => $acceptedAt ? '2026-01-01 00:00:00' : null, 'accepted_user_id' => $acceptedUser,
        ]);
    }

    // ------------------------------------------------------------------ Fase 2 (cadastros)

    public static function company(int $tenant, string $cnpj = '11222333000181', array $extra = []): int
    {
        return (int) DB::table('companies')->insertGetId([
            'tenant_id' => $tenant, 'uuid' => (string) Str::uuid(), 'legal_name' => 'DEMO Empresa '.Str::random(5),
            'document_number' => $cnpj, 'status' => 'active', ...$extra,
        ]);
    }

    public static function branch(int $tenant, int $company, ?string $cnpj = null): int
    {
        return (int) DB::table('company_branches')->insertGetId([
            'tenant_id' => $tenant, 'company_id' => $company, 'name' => 'DEMO Filial', 'document_number' => $cnpj,
        ]);
    }

    public static function customer(int $tenant, int $company, ?string $doc = null, string $type = 'person', array $extra = []): int
    {
        return (int) DB::table('customers')->insertGetId([
            'tenant_id' => $tenant, 'uuid' => (string) Str::uuid(), 'company_id' => $company, 'type' => $type,
            'document_number' => $doc, 'name' => 'DEMO Cliente', ...$extra,
        ]);
    }

    public static function supplier(int $tenant, int $company, string $doc = '11222333000181', string $type = 'company', array $extra = []): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'tenant_id' => $tenant, 'uuid' => (string) Str::uuid(), 'company_id' => $company, 'type' => $type,
            'document_number' => $doc, 'legal_name' => 'DEMO Fornecedor', ...$extra,
        ]);
    }

    public static function address(string $table, string $ownerColumn, int $tenant, int $owner, array $extra = []): int
    {
        return (int) DB::table($table)->insertGetId([
            'tenant_id' => $tenant, $ownerColumn => $owner, 'kind' => 'main', 'street' => 'Rua DEMO',
            'city' => 'Cidade DEMO', 'state' => 'MT', 'postal_code' => '78000000', ...$extra,
        ]);
    }

    public static function contact(string $table, string $ownerColumn, int $tenant, int $owner, bool $primary = false): int
    {
        return (int) DB::table($table)->insertGetId([
            'tenant_id' => $tenant, $ownerColumn => $owner, 'name' => 'Contato DEMO', 'is_primary' => $primary,
        ]);
    }

    /** Executa e devolve o código de erro do MySQL, ou null se NÃO houve erro (operação aceita). */
    public static function errno(callable $fn): ?int
    {
        try {
            $fn();

            return null;
        } catch (QueryException $e) {
            $previous = $e->getPrevious();

            return (int) ($previous?->errorInfo[1] ?? $e->errorInfo[1] ?? 0);
        }
    }
}
