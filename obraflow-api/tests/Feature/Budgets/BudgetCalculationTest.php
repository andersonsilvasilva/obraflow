<?php

use App\Models\Budget;
use App\Models\BudgetGroup;
use App\Models\BudgetItem;
use App\Models\BudgetVersion;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Project;
use App\Modules\Budgets\Exceptions\VersionLockedException;
use App\Modules\Budgets\Services\BdiFormula;
use App\Modules\Budgets\Services\BudgetCalculator;
use App\Modules\Budgets\Services\BudgetService;
use App\Modules\Budgets\Support\Dec;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Shared\Exceptions\DomainRuleException;
use App\Modules\Tenancy\Exceptions\MissingTenantContext;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\Scenario;

/*
 * Fase 4B.4 — cálculo do orçamento (D-P02, D-P32, D-P33) e imutabilidade (D-08, D-P31).
 * Valores esperados conferidos à parte com aritmética decimal independente (Python Decimal, ROUND_HALF_UP).
 */

beforeEach(function () {
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->user = Scenario::member($this->A, 'admin');
    $this->svc = app(BudgetService::class);
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->A, $fn, $this->user);
    $this->co = ($this->in)(fn () => Company::create(['legal_name' => 'DEMO', 'document_number' => '11222333000181']));
    $this->budget = ($this->in)(fn () => $this->svc->create(['company_id' => $this->co->id, 'code' => 'ORC-1', 'title' => 'DEMO Orçamento', 'mode' => 'manual'], $this->user));
    $this->v = $this->budget->currentVersion;
});

/** Cenário do teste de valores: G1 (raiz) ⊃ G2; BDI global 25, G2 10, item 3 com 30 próprio; indiretos e desconto. */
function cenario(object $t): array
{
    return ($t->in)(function () use ($t) {
        $g1 = $t->svc->addGroup($t->v, ['code' => '1', 'name' => 'DEMO Estrutura'], $t->user);
        $g2 = $t->svc->addGroup($t->v, ['code' => '1.1', 'name' => 'DEMO Fundação', 'parent_id' => $g1->id], $t->user);
        $i1 = $t->svc->addItem($t->v, ['group_id' => $g1->id, 'code' => '1.01', 'description' => 'DEMO A', 'unit_code' => 'M', 'quantity' => '12.5', 'unit_cost' => '18.34', 'discount_percent' => '10'], $t->user);
        $i2 = $t->svc->addItem($t->v, ['group_id' => $g2->id, 'code' => '1.1.01', 'description' => 'DEMO B', 'unit_code' => 'M2', 'quantity' => '3.3333', 'unit_cost' => '7.77'], $t->user);
        $i3 = $t->svc->addItem($t->v, ['group_id' => $g1->id, 'code' => '1.02', 'description' => 'DEMO C', 'unit_code' => 'UN', 'quantity' => '2', 'unit_cost' => '100.00', 'bdi_percent' => '30'], $t->user);
        $t->svc->setBdi($t->v, 'global', null, 'informed', '25', null, $t->user);
        $t->svc->setBdi($t->v, 'group', $g2->id, 'informed', '10', null, $t->user);
        $t->svc->addIndirect($t->v, ['description' => 'DEMO Canteiro', 'method' => 'fixed', 'amount' => '500.00'], $t->user);
        $t->svc->addIndirect($t->v, ['description' => 'DEMO Administração local', 'method' => 'percent_of_direct', 'percent' => '5'], $t->user);
        $totals = $t->svc->updateVersion($t->v, ['discount_percent' => '3'], $t->user);

        return compact('g1', 'g2', 'i1', 'i2', 'i3', 'totals');
    });
}

it('calcula item a item (arredondando cada um) e a versão com BDI por escopo, indiretos e desconto global', function () {
    ['g1' => $g1, 'g2' => $g2, 'i1' => $i1, 'i2' => $i2, 'i3' => $i3, 'totals' => $t] = cenario($this);

    $items = ($this->in)(fn () => BudgetItem::query()->orderBy('id')->get(['id', 'total_cost', 'total_sale'])->keyBy('id'));
    expect($items[$i1->id]->total_cost)->toBe('229.25')->and($items[$i1->id]->total_sale)->toBe('257.90') // BDI global 25, desconto 10
        ->and($items[$i2->id]->total_cost)->toBe('25.90')->and($items[$i2->id]->total_sale)->toBe('28.49')   // BDI da etapa G2 (10)
        ->and($items[$i3->id]->total_cost)->toBe('200.00')->and($items[$i3->id]->total_sale)->toBe('260.00'); // BDI próprio do item (30)

    expect($t)->toMatchArray([
        'direct_cost' => '455.15', 'indirect_cost' => '522.76', 'total_cost' => '977.91', 'bdi_amount' => '119.90',
        'items_discount' => '28.66', 'items_sale' => '546.39', 'global_discount' => '32.07', 'final_price' => '1037.08', 'item_count' => 3,
    ])->and($t['groups'][$g1->id])->toBe(['cost' => '455.15', 'sale' => '546.39']) // inclui a subetapa
        ->and($t['groups'][$g2->id])->toBe(['cost' => '25.90', 'sale' => '28.49']);

    // o snapshot fica gravado na versão
    expect(($this->in)(fn () => BudgetVersion::query()->find($this->v->id)->totals_snapshot['final_price']))->toBe('1037.08');
});

it('recalcula a cada mudança: alterar quantidade, trocar BDI e excluir item atualizam os totais', function () {
    ['i1' => $i1, 'i3' => $i3] = cenario($this);

    $t = ($this->in)(function () use ($i1) {
        $this->svc->updateItem($i1, ['quantity' => '0'], $this->user);

        return BudgetVersion::query()->find($this->v->id)->totals_snapshot;
    });
    expect($t['direct_cost'])->toBe('225.90');

    ($this->in)(fn () => $this->svc->setBdi($this->v, 'global', null, 'informed', null, null, $this->user)); // remove o global
    ($this->in)(fn () => $this->svc->deleteItem($i3, $this->user));
    $t = ($this->in)(fn () => BudgetVersion::query()->find($this->v->id)->totals_snapshot);
    expect($t['direct_cost'])->toBe('25.90')->and($t['items_sale'])->toBe('28.49')->and($t['item_count'])->toBe(2);
});

it('BDI pela fórmula do TCU guarda os componentes e calcula o percentual', function () {
    $c = ['ac' => '4.00', 's' => '0.40', 'r' => '0.97', 'g' => '0.40', 'df' => '1.23', 'l' => '7.40', 'i' => '8.65'];
    expect(BdiFormula::tcu($c))->toBe('25.88')
        ->and(fn () => BdiFormula::tcu(['i' => '100']))->toThrow(DomainRuleException::class)
        ->and(fn () => BdiFormula::tcu(['l' => '-1']))->toThrow(DomainRuleException::class);

    $bdi = ($this->in)(fn () => $this->svc->setBdi($this->v, 'global', null, 'tcu', null, $c, $this->user));
    expect($bdi->percent)->toBe('25.8800')->and($bdi->components)->toBe($c);
});

it('arredondamento meio-para-cima e aritmética exata (sem float)', function () {
    expect(Dec::round('2.345'))->toBe('2.35')->and(Dec::round('2.344999'))->toBe('2.34')->and(Dec::round('-2.345'))->toBe('-2.35')
        ->and(Dec::round(Dec::mul('0.1', '3')))->toBe('0.30')
        ->and(Dec::factor('25'))->toBe('1.250000000000')->and(Dec::factor('10', minus: true))->toBe('0.900000000000');
});

// ---------------------------------------------------------------- imutabilidade (D-08, D-P31)
function aprovar(object $t, BudgetVersion $v): void
{
    ($t->in)(function () use ($v) {
        DB::table('budget_versions')->where('id', $v->id)->update(['status' => 'approved', 'locked_at' => now(), 'approved_at' => now()]);
    });
}

it('versão aprovada é somente leitura: itens, etapas, BDI, indiretos, desconto e recálculo são recusados', function () {
    ['g1' => $g1, 'i1' => $i1] = cenario($this);
    aprovar($this, $this->v);

    foreach ([
        fn () => $this->svc->addItem($this->v, ['group_id' => $g1->id, 'code' => 'x', 'description' => 'x', 'unit_code' => 'UN', 'quantity' => '1', 'unit_cost' => '1'], $this->user),
        fn () => $this->svc->updateItem($i1->fresh(), ['quantity' => '1'], $this->user),
        fn () => $this->svc->deleteItem($i1->fresh(), $this->user),
        fn () => $this->svc->addGroup($this->v, ['code' => '9', 'name' => 'x'], $this->user),
        fn () => $this->svc->setBdi($this->v, 'global', null, 'informed', '1', null, $this->user),
        fn () => $this->svc->addIndirect($this->v, ['description' => 'x', 'method' => 'fixed', 'amount' => '1'], $this->user),
        fn () => $this->svc->updateVersion($this->v->fresh(), ['discount_percent' => '1'], $this->user),
        fn () => app(BudgetCalculator::class)->recalculate($this->v),
        fn () => BudgetVersion::query()->find($this->v->id)->delete(),
    ] as $i => $write) {
        expect(fn () => ($this->in)($write))->toThrow(VersionLockedException::class, null, "escrita #{$i} deveria ser recusada");
    }

    // a única mudança aceita: aprovada → substituída
    ($this->in)(fn () => BudgetVersion::query()->find($this->v->id)->forceFill(['status' => 'superseded'])->save());
    expect(($this->in)(fn () => BudgetVersion::query()->find($this->v->id)->status))->toBe('superseded');
});

it('nova versão copia etapas (árvore), itens, BDI e indiretos com os mesmos totais; exige a atual fechada', function () {
    ['totals' => $before] = cenario($this);

    expect(fn () => ($this->in)(fn () => $this->svc->newVersion($this->budget->fresh(), $this->user)))
        ->toThrow(ApiException::class); // rascunho: edita-se a própria versão

    aprovar($this, $this->v);
    $v2 = ($this->in)(fn () => $this->svc->newVersion($this->budget->fresh(), $this->user));

    expect($v2->version_number)->toBe(2)->and($v2->status)->toBe('draft')->and($v2->isLocked())->toBeFalse()
        ->and(($this->in)(fn () => $this->budget->fresh()->current_version_id))->toBe($v2->id)
        ->and(collect($v2->totals_snapshot)->except(['groups', 'calculated_at'])->all())->toEqual(collect($before)->except(['groups', 'calculated_at'])->all());

    $g2copia = ($this->in)(fn () => BudgetGroup::query()->where('budget_version_id', $v2->id)->where('code', '1.1')->sole());
    expect(($this->in)(fn () => $g2copia->parent->code))->toBe('1') // árvore remapeada para a nova versão
        ->and(($this->in)(fn () => $g2copia->parent->budget_version_id))->toBe($v2->id);

    // a versão nova é editável e independente da aprovada
    ($this->in)(fn () => $this->svc->updateVersion($v2, ['discount_percent' => '0'], $this->user));
    expect(($this->in)(fn () => BudgetVersion::query()->find($this->v->id)->totals_snapshot['final_price']))->toBe('1037.08');
});

// ---------------------------------------------------------------- regras de modelo e de criação
it('orçamento por referência herda UF e regime da base ativa; sem base ativa é recusado', function () {
    $src = DB::table('reference_sources')->insertGetId(['code' => 'SINAPI', 'name' => 'SINAPI']);
    $ds = DB::table('reference_datasets')->insertGetId(['source_id' => $src, 'version' => '2024-12', 'state' => 'MT', 'charges_regime' => 'without_relief', 'status' => 'active']);
    $draft = DB::table('reference_datasets')->insertGetId(['source_id' => $src, 'version' => '2025-01', 'state' => 'MT', 'charges_regime' => 'without_relief', 'status' => 'importing']);

    $b = ($this->in)(fn () => $this->svc->create(['company_id' => $this->co->id, 'code' => 'ORC-R', 'title' => 'DEMO', 'mode' => 'reference', 'reference_dataset_id' => $ds, 'state' => 'SP', 'social_charges_regime' => 'with_relief'], $this->user));
    expect($b->state)->toBe('MT')->and($b->social_charges_regime)->toBe('without_relief')
        ->and($b->currentVersion->social_charges_regime)->toBe('without_relief')
        ->and(fn () => ($this->in)(fn () => $this->svc->create(['company_id' => $this->co->id, 'code' => 'ORC-X', 'title' => 'x', 'mode' => 'reference', 'reference_dataset_id' => $draft], $this->user)))
        ->toThrow(DomainRuleException::class);
});

it('obra e cliente do orçamento precisam ser da mesma empresa; etapas até 4 níveis sem ciclo', function () {
    $outra = ($this->in)(fn () => Company::create(['legal_name' => 'DEMO 2', 'document_number' => '11444777000161']));
    $cliente = ($this->in)(fn () => Customer::create(['company_id' => $outra->id, 'type' => 'person', 'name' => 'DEMO']));
    $obra = ($this->in)(function () use ($outra) {
        $p = new Project(['company_id' => $outra->id, 'code' => 'O-1', 'name' => 'DEMO']);
        $p->created_by = $this->user->id;
        $p->save();

        return $p;
    });

    expect(fn () => ($this->in)(fn () => $this->svc->update($this->budget, ['customer_id' => $cliente->id], $this->user)))->toThrow(DomainRuleException::class)
        ->and(fn () => ($this->in)(fn () => $this->svc->update($this->budget, ['project_id' => $obra->id], $this->user)))->toThrow(DomainRuleException::class);

    $g = ($this->in)(function () {
        $parent = null;
        for ($i = 1; $i <= 4; $i++) {
            $parent = $this->svc->addGroup($this->v, ['code' => (string) $i, 'name' => "N{$i}", 'parent_id' => $parent?->id], $this->user);
        }

        return $parent;
    });
    expect(fn () => ($this->in)(fn () => $this->svc->addGroup($this->v, ['code' => '5', 'name' => 'N5', 'parent_id' => $g->id], $this->user)))->toThrow(DomainRuleException::class);
    $raiz = ($this->in)(fn () => BudgetGroup::query()->where('code', '1')->sole());
    expect(fn () => ($this->in)(fn () => $this->svc->updateGroup($raiz, ['parent_id' => $g->id], $this->user)))->toThrow(DomainRuleException::class);
});

it('modelos de orçamento exigem tenant ativo (fail-closed)', function (string $model) {
    expect(fn () => $model::query()->count())->toThrow(MissingTenantContext::class);
})->with([Budget::class, BudgetVersion::class, BudgetGroup::class, BudgetItem::class]);
