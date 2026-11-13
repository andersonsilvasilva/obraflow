<?php

use Illuminate\Support\Facades\DB;

const PHASE1_TABLES = [
    'audit_logs', 'failed_jobs', 'model_has_permissions', 'model_has_roles', 'password_reset_tokens',
    'permissions', 'personal_access_tokens', 'role_has_permissions', 'roles', 'tenant_domains',
    'tenant_settings', 'tenant_users', 'tenants', 'user_invitations', 'users',
];

/** Fase 2 (FASE2_MODELO.md), acrescentadas por etapa. */
const PHASE2_TABLES = [
    'companies', 'company_addresses', 'company_branch_addresses', 'company_branch_contacts', 'company_branches',
    'company_contacts', 'units_of_measure',
];

function dbName(): string
{
    return DB::connection()->getDatabaseName();
}

it('cria exatamente as tabelas das Fases 1 e 2 (mais a tabela migrations)', function () {
    $tables = collect(DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ?', [dbName()]))
        ->pluck('t')->map(fn ($t) => strtolower($t))->reject(fn ($t) => $t === 'migrations')->sort()->values()->all();

    $expected = collect([...PHASE1_TABLES, ...PHASE2_TABLES])->sort()->values()->all();

    expect($tables)->toBe($expected);
});

it('todas as tabelas possuem PRIMARY KEY (compatível com sql_require_primary_key=1)', function () {
    $semPk = DB::select(
        "SELECT t.table_name AS t FROM information_schema.tables t
         LEFT JOIN information_schema.table_constraints c
           ON c.table_schema = t.table_schema AND c.table_name = t.table_name AND c.constraint_type = 'PRIMARY KEY'
         WHERE t.table_schema = ? AND c.constraint_name IS NULL",
        [dbName()],
    );

    expect($semPk)->toBeEmpty();
});

it('todas as chaves estrangeiras são RESTRICT (nenhuma CASCADE/SET NULL)', function () {
    $diferentes = DB::select(
        "SELECT constraint_name AS c FROM information_schema.referential_constraints
         WHERE constraint_schema = ? AND (delete_rule <> 'RESTRICT' OR update_rule <> 'RESTRICT')",
        [dbName()],
    );

    expect($diferentes)->toBeEmpty();
});

it('users é global: não possui tenant_id', function () {
    $col = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = ? AND table_name = 'users' AND column_name = 'tenant_id'", [dbName()]);

    expect((int) $col->n)->toBe(0);
});

it('roles.tenant_id é NOT NULL e não há papéis globais possíveis', function () {
    $col = DB::selectOne("SELECT is_nullable AS n FROM information_schema.columns WHERE table_schema = ? AND table_name = 'roles' AND column_name = 'tenant_id'", [dbName()]);

    expect($col->n)->toBe('NO');
});

it('não existem triggers no esquema (D-22)', function () {
    $n = DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.triggers WHERE trigger_schema = ?', [dbName()]);

    expect((int) $n->n)->toBe(0);
});

it('as FKs compostas de isolamento existem', function () {
    $fks = collect(DB::select(
        'SELECT constraint_name AS c FROM information_schema.referential_constraints WHERE constraint_schema = ?',
        [dbName()],
    ))->pluck('c')->all();

    foreach (['fk_mhr_role', 'fk_mhr_member', 'fk_mhp_member', 'fk_pat_member', 'fk_ui_inviter', 'fk_ui_role', 'fk_ui_accepted'] as $fk) {
        expect($fks)->toContain($fk);
    }
});

it('os CHECK constraints estão ativos (e aplicados) e as colunas geradas existem', function () {
    foreach (['chk_users_status', 'chk_tenants_status', 'chk_tu_status', 'chk_mhr_type', 'chk_mhp_type', 'chk_pat_type', 'chk_ui_status', 'chk_ui_accepted'] as $chk) {
        $row = DB::selectOne('SELECT enforced AS e FROM information_schema.table_constraints WHERE constraint_schema = ? AND constraint_name = ?', [dbName(), $chk]);
        expect($row)->not->toBeNull("CHECK {$chk} ausente")->and($row->e)->toBe('YES');
    }

    $generated = collect(DB::select(
        "SELECT CONCAT(table_name, '.', column_name) AS c FROM information_schema.columns WHERE table_schema = ? AND generation_expression <> ''",
        [dbName()],
    ))->pluck('c')->sort()->values()->all();

    expect($generated)->toBe([
        'companies.active_marker', 'company_addresses.main_marker', 'company_branch_addresses.main_marker',
        'company_branch_contacts.primary_marker', 'company_branches.active_marker', 'company_contacts.primary_marker',
        'tenant_domains.primary_marker', 'user_invitations.pending_email',
    ]);
});

it('audit_logs não possui updated_at e guarda microssegundos', function () {
    $updated = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = ? AND table_name = 'audit_logs' AND column_name = 'updated_at'", [dbName()]);
    $prec = DB::selectOne("SELECT datetime_precision AS p FROM information_schema.columns WHERE table_schema = ? AND table_name = 'audit_logs' AND column_name = 'created_at'", [dbName()]);

    expect((int) $updated->n)->toBe(0)->and((int) $prec->p)->toBe(6);
});

it('token_hash usa ascii_bin e email usa utf8mb4_0900_as_ci', function () {
    $hash = DB::selectOne("SELECT collation_name AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = 'user_invitations' AND column_name = 'token_hash'", [dbName()]);
    $email = DB::selectOne("SELECT collation_name AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = 'users' AND column_name = 'email'", [dbName()]);

    expect($hash->c)->toBe('ascii_bin')->and($email->c)->toBe('utf8mb4_0900_as_ci');
});
