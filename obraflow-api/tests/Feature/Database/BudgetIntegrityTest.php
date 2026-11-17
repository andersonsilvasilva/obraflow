<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Fx;

/*
 * Fase 4B.4 — integridade dos orçamentos no banco: FKs compostas recusam vínculo entre tenants e entre VERSÕES
 * (1452), CHECKs recusam domínio inválido (3819), unicidade (1062), em uso (1451).
 */

beforeEach(function () {
    $this->A = Fx::tenant();
    $this->B = Fx::tenant();
    $this->user = Fx::user();
    Fx::member($this->A, $this->user);
    Fx::member($this->B, $this->user);
    $this->coA = Fx::company($this->A);
    $this->coB = Fx::company($this->B);
});

function budget(int $tenant, int $company, int $owner, array $x = []): int
{
    return (int) DB::table('budgets')->insertGetId(['tenant_id' => $tenant, 'uuid' => (string) Str::uuid(), 'company_id' => $company,
        'code' => 'ORC-'.Str::random(5), 'title' => 'DEMO', 'mode' => 'manual', 'social_charges_regime' => 'not_applicable', 'owner_id' => $owner, ...$x]);
}

function version(int $tenant, int $budget, int $user, int $n = 1, array $x = []): int
{
    return (int) DB::table('budget_versions')->insertGetId(['tenant_id' => $tenant, 'budget_id' => $budget, 'version_number' => $n,
        'social_charges_regime' => 'not_applicable', 'created_by' => $user, ...$x]);
}

function bgroup(int $tenant, int $version, ?int $parent = null): int
{
    return (int) DB::table('budget_groups')->insertGetId(['tenant_id' => $tenant, 'budget_version_id' => $version, 'parent_id' => $parent, 'code' => '1', 'name' => 'DEMO']);
}

function bitem(int $tenant, int $version, int $group, array $x = []): int
{
    return (int) DB::table('budget_items')->insertGetId(['tenant_id' => $tenant, 'budget_version_id' => $version, 'group_id' => $group,
        'code' => '1', 'description' => 'DEMO', 'unit_code' => 'UN', 'quantity' => 1, 'unit_cost' => 1, ...$x]);
}

it('orçamento: empresa, obra, cliente e dono do mesmo tenant; código único entre os ativos; modo referência exige base', function () {
    $obraB = Fx::project($this->B, $this->coB, $this->user);
    $estranho = Fx::user();

    expect(Fx::errno(fn () => budget($this->A, $this->coB, $this->user)))->toBe(1452)
        ->and(Fx::errno(fn () => budget($this->A, $this->coA, $this->user, ['project_id' => $obraB])))->toBe(1452)
        ->and(Fx::errno(fn () => budget($this->A, $this->coA, $estranho)))->toBe(1452)
        ->and(Fx::errno(fn () => budget($this->A, $this->coA, $this->user, ['code' => 'X'])))->toBeNull()
        ->and(Fx::errno(fn () => budget($this->A, $this->coA, $this->user, ['code' => 'X'])))->toBe(1062)
        ->and(Fx::errno(fn () => budget($this->A, $this->coA, $this->user, ['mode' => 'reference'])))->toBe(3819)
        ->and(Fx::errno(fn () => budget($this->A, $this->coA, $this->user, ['status' => 'closed'])))->toBe(3819)
        ->and(Fx::errno(fn () => budget($this->A, $this->coA, $this->user, ['social_charges_regime' => 'mixed'])))->toBe(3819);
});

it('versão corrente precisa ser do PRÓPRIO orçamento; versão aprovada exige bloqueio', function () {
    $b1 = budget($this->A, $this->coA, $this->user);
    $b2 = budget($this->A, $this->coA, $this->user);
    $v2 = version($this->A, $b2, $this->user);

    expect(Fx::errno(fn () => DB::table('budgets')->where('id', $b1)->update(['current_version_id' => $v2])))->toBe(1452)
        ->and(Fx::errno(fn () => DB::table('budgets')->where('id', $b2)->update(['current_version_id' => $v2])))->toBeNull()
        ->and(Fx::errno(fn () => version($this->A, $b1, $this->user, 1, ['status' => 'approved'])))->toBe(3819) // sem locked_at
        ->and(Fx::errno(fn () => version($this->A, $b1, $this->user, 1, ['status' => 'approved', 'locked_at' => now()])))->toBeNull()
        ->and(Fx::errno(fn () => version($this->A, $b1, $this->user, 1)))->toBe(1062)
        ->and(Fx::errno(fn () => version($this->A, $b1, $this->user, 2, ['discount_percent' => 100])))->toBe(3819);
});

it('etapa-pai e etapa do item precisam ser da MESMA versão (e do mesmo tenant)', function () {
    $b = budget($this->A, $this->coA, $this->user);
    [$v1, $v2] = [version($this->A, $b, $this->user, 1), version($this->A, $b, $this->user, 2)];
    $g1 = bgroup($this->A, $v1);
    $g2 = bgroup($this->A, $v2);

    expect(Fx::errno(fn () => bgroup($this->A, $v1, $g1)))->toBeNull()
        ->and(Fx::errno(fn () => bgroup($this->A, $v1, $g2)))->toBe(1452)
        ->and(Fx::errno(fn () => bitem($this->A, $v1, $g2)))->toBe(1452)
        ->and(Fx::errno(fn () => bitem($this->B, $v1, $g1)))->toBe(1452)
        ->and(Fx::errno(fn () => bitem($this->A, $v1, $g1)))->toBeNull()
        ->and(Fx::errno(fn () => DB::table('budget_groups')->where('id', $g1)->delete()))->toBe(1451);
});

it('item: valores e percentuais no domínio (CHECK)', function () {
    $b = budget($this->A, $this->coA, $this->user);
    $v = version($this->A, $b, $this->user);
    $g = bgroup($this->A, $v);

    expect(Fx::errno(fn () => bitem($this->A, $v, $g, ['quantity' => -1])))->toBe(3819)
        ->and(Fx::errno(fn () => bitem($this->A, $v, $g, ['unit_cost' => -0.01])))->toBe(3819)
        ->and(Fx::errno(fn () => bitem($this->A, $v, $g, ['discount_percent' => 100])))->toBe(3819)
        ->and(Fx::errno(fn () => bitem($this->A, $v, $g, ['bdi_percent' => -1])))->toBe(3819)
        ->and(Fx::errno(fn () => bitem($this->A, $v, $g, ['source' => 'sinapi'])))->toBe(3819);
});

it('BDI: um por alvo; alvo coerente com o escopo e da mesma versão; TCU exige componentes', function () {
    $b = budget($this->A, $this->coA, $this->user);
    [$v1, $v2] = [version($this->A, $b, $this->user, 1), version($this->A, $b, $this->user, 2)];
    $g1 = bgroup($this->A, $v1);
    $g2 = bgroup($this->A, $v2);
    $i1 = bitem($this->A, $v1, $g1);
    $bdi = fn (array $x) => DB::table('budget_bdi')->insert(['tenant_id' => $this->A, 'budget_version_id' => $v1, 'percent' => 25, ...$x]);

    expect(Fx::errno(fn () => $bdi(['scope' => 'global'])))->toBeNull()
        ->and(Fx::errno(fn () => $bdi(['scope' => 'global'])))->toBe(1062)
        ->and(Fx::errno(fn () => $bdi(['scope' => 'group', 'group_id' => $g1])))->toBeNull()
        ->and(Fx::errno(fn () => $bdi(['scope' => 'group', 'group_id' => $g2])))->toBe(1452) // etapa de outra versão
        ->and(Fx::errno(fn () => $bdi(['scope' => 'item', 'item_id' => $i1])))->toBeNull()
        ->and(Fx::errno(fn () => $bdi(['scope' => 'item', 'group_id' => $g1])))->toBe(3819)
        ->and(Fx::errno(fn () => $bdi(['scope' => 'global', 'item_id' => $i1])))->toBe(3819)
        ->and(Fx::errno(fn () => DB::table('budget_bdi')->insert(['tenant_id' => $this->A, 'budget_version_id' => $v2, 'scope' => 'global', 'method' => 'tcu', 'percent' => 25])))->toBe(3819);
});

it('custo indireto: fixo exige valor, percentual exige %, nunca os dois', function () {
    $b = budget($this->A, $this->coA, $this->user);
    $v = version($this->A, $b, $this->user);
    $ind = fn (array $x) => DB::table('budget_indirect_costs')->insert(['tenant_id' => $this->A, 'budget_version_id' => $v, 'description' => 'DEMO', ...$x]);

    expect(Fx::errno(fn () => $ind(['method' => 'fixed', 'amount' => 10])))->toBeNull()
        ->and(Fx::errno(fn () => $ind(['method' => 'percent_of_direct', 'percent' => 5])))->toBeNull()
        ->and(Fx::errno(fn () => $ind(['method' => 'fixed'])))->toBe(3819)
        ->and(Fx::errno(fn () => $ind(['method' => 'fixed', 'amount' => 10, 'percent' => 5])))->toBe(3819)
        ->and(Fx::errno(fn () => $ind(['method' => 'percent_of_direct', 'amount' => 10])))->toBe(3819)
        ->and(Fx::errno(fn () => DB::table('budget_indirect_costs')->insert(['tenant_id' => $this->B, 'budget_version_id' => $v, 'description' => 'x', 'method' => 'fixed', 'amount' => 1])))->toBe(1452);
});
