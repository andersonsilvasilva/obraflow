<?php

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/*
 * Fase 2 — etapa 5: revisão estrutural do isolamento. Estes testes quebram se uma tabela/modelo novo
 * nascer sem as garantias (em vez de depender de alguém lembrar de testar).
 */

/** Tabelas da Fase 1 com tenant_id que, por decisão documentada, NÃO usam o escopo global no modelo. */
const TENANT_SCOPE_EXCEPTIONS = [
    'roles',                  // D-24: cache global do Spatie; consultas usam forTenant()
    'tenant_users',           // vínculo: consultado por usuário em vários tenants (seleção de tenant)
    'model_has_roles', 'model_has_permissions', 'personal_access_tokens', 'user_invitations', 'audit_logs',
    'tenant_domains', // sem uso até a pré-seleção por subdomínio
];

function tenantTables(): array
{
    return collect(DB::select(
        "SELECT table_name AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'tenant_id'"
    ))->pluck('t')->map(fn ($t) => strtolower($t))->reject(fn ($t) => in_array($t, TENANT_SCOPE_EXCEPTIONS, true))->sort()->values()->all();
}

it('toda tabela com tenant_id (fora das exceções documentadas) tem modelo com BelongsToTenant', function () {
    $models = collect(glob(app_path('Models/*.php')))
        ->map(fn ($f) => 'App\\Models\\'.basename($f, '.php'))
        ->filter(fn ($c) => is_subclass_of($c, Model::class))
        ->mapWithKeys(fn ($c) => [(new $c)->getTable() => $c]);

    $semModelo = [];
    $semEscopo = [];
    foreach (tenantTables() as $table) {
        $class = $models[$table] ?? null;
        if ($class === null) {
            $semModelo[] = $table;

            continue;
        }
        if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            $semEscopo[] = $class;
        }
    }

    expect($semModelo)->toBe([], 'tabelas com tenant_id sem modelo: '.implode(', ', $semModelo))
        ->and($semEscopo)->toBe([], 'modelos sem BelongsToTenant: '.implode(', ', $semEscopo));
});

it('toda FK entre tabelas operacionais é composta com tenant_id (nenhuma FK simples atravessa tenants)', function () {
    $tables = tenantTables();
    $fks = DB::select(
        "SELECT k.constraint_name AS c, k.table_name AS t, k.referenced_table_name AS r,
                GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position) AS cols
         FROM information_schema.key_column_usage k
         WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL
           AND k.referenced_table_name NOT IN ('tenants', 'users', 'permissions')
         GROUP BY k.constraint_name, k.table_name, k.referenced_table_name",
    );

    $simples = collect($fks)
        ->filter(fn ($fk) => in_array(strtolower($fk->t), $tables, true))
        ->reject(fn ($fk) => str_starts_with($fk->cols, 'tenant_id,'))
        ->map(fn ($fk) => "{$fk->t}.{$fk->c} ({$fk->cols} → {$fk->r})")->values()->all();

    expect($simples)->toBe([]);
});

it('toda tabela operacional tem UNIQUE (tenant_id, id) e tenant_id NOT NULL', function () {
    // tenant_settings (Fase 1, homologada) não é alvo de nenhuma FK composta, logo não precisa do índice.
    foreach (array_diff(tenantTables(), ['tenant_settings']) as $table) {
        $nullable = DB::selectOne(
            "SELECT is_nullable AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'tenant_id'",
            [$table],
        )->n;
        expect($nullable)->toBe('NO', "{$table}.tenant_id deveria ser NOT NULL");

        $hasKey = collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Non_unique = 0"))
            ->groupBy('Key_name')
            ->contains(fn ($cols) => $cols->sortBy('Seq_in_index')->pluck('Column_name')->all() === ['tenant_id', 'id']);
        expect($hasKey)->toBeTrue("{$table} sem UNIQUE (tenant_id, id)");
    }
});
