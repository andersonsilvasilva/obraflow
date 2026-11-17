<?php

use App\Models\BudgetComposition;
use App\Models\BudgetCompositionItem;
use App\Models\BudgetItem;
use App\Models\BudgetItemResource;
use App\Models\Company;
use App\Models\ReferenceDataset;
use App\Modules\Budgets\Exceptions\VersionLockedException;
use App\Modules\Budgets\Services\AbcCurve;
use App\Modules\Budgets\Services\BudgetService;
use App\Modules\Shared\Exceptions\DomainRuleException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fx;
use Tests\Support\Scenario;
use Tests\Support\SinapiFixture;

/*
 * Fase 4B.5 — itens a partir das referências (cópia autossuficiente, D-08), insumos finais com a árvore expandida
 * e Curva ABC (D-P33). Recorte SINAPI importado pelo próprio comando.
 */

beforeEach(function () {
    Scenario::catalog();
    Artisan::call('obraflow:references:import', ['--competency' => '2024-12', '--state' => 'MT', '--regime' => 'with_relief',
        '--resources' => SinapiFixture::insumos(), '--compositions' => SinapiFixture::analitico(), '--families' => SinapiFixture::familias(), '--activate' => true]);
    $this->ds = ReferenceDataset::query()->sole();
    $this->A = Scenario::tenant();
    $this->user = Scenario::member($this->A, 'admin');
    $this->svc = app(BudgetService::class);
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->A, $fn, $this->user);
    [$this->budget, $this->g] = ($this->in)(function () {
        $co = Company::create(['legal_name' => 'DEMO', 'document_number' => '11222333000181']);
        $b = $this->svc->create(['company_id' => $co->id, 'code' => 'ORC-1', 'title' => 'DEMO', 'mode' => 'reference', 'reference_dataset_id' => $this->ds->id], $this->user);

        return [$b, $this->svc->addGroup($b->currentVersion, ['code' => '1', 'name' => 'DEMO Rede'], $this->user)];
    });
    $this->v = $this->budget->currentVersion;
    $this->add = fn (string $type, string $code, string $qty, array $x = []) => ($this->in)(fn () => $this->svc->addReferenceItem($this->v,
        ['reference_dataset_id' => $this->ds->id, 'type' => $type, 'code' => $code, 'group_id' => $this->g->id, 'quantity' => $qty] + $x, $this->user));
});

it('item de composição copia descrição, unidade, custo publicado, composição com itens diretos e a origem', function () {
    $item = ($this->add)('composition', '97141', '10');

    expect($item->source)->toBe('official')->and($item->unit_cost)->toBe('3.84')->and($item->total_cost)->toBe('38.40')
        ->and($item->unit_code)->toBe('M')->and($item->code)->toBe('97141')
        ->and($item->source_ref)->toMatchArray(['source' => 'SINAPI', 'competency' => '2024-12', 'state' => 'MT', 'regime' => 'with_relief', 'type' => 'composition', 'code' => '97141']);

    [$comp, $itens] = ($this->in)(fn () => [BudgetComposition::query()->where('budget_item_id', $item->id)->sole(), BudgetCompositionItem::query()->orderBy('sort_order')->get()]);
    expect($comp->unit_cost)->toBe('3.84')->and($comp->source_snapshot['class'])->toBe('ASTU')
        ->and($itens->map->only(['item_type', 'code', 'coefficient', 'cost'])->all())->toBe([
            ['item_type' => 'resource', 'code' => '20078', 'coefficient' => '0.0046000', 'cost' => '0.14'],
            ['item_type' => 'resource', 'code' => '99999', 'coefficient' => '1.5000000', 'cost' => '3.00'],
            ['item_type' => 'composition', 'code' => '88316', 'coefficient' => '0.0320000', 'cost' => '0.58'],
        ]);
});

it('insumos finais expandem a árvore: o insumo da composição auxiliar entra com o coeficiente acumulado', function () {
    $item = ($this->add)('composition', '97141', '10');

    $res = ($this->in)(fn () => BudgetItemResource::query()->where('budget_item_id', $item->id)->orderBy('code')->get()->keyBy('code'));
    expect($res->pluck('code')->all())->toBe(['20078', '4750', '99999'])
        ->and($res['4750']->coefficient)->toBe('0.0320000') // 0,032 (97141→88316) × 1,0 (88316→4750)
        ->and($res['4750']->kind)->toBe('labor')
        ->and($res['4750']->unit_price)->toBe('1234.56')
        ->and($res['99999']->unit_price)->toBe('2.00'); // sem preço na base: usa o preço publicado na linha da composição
});

it('Curva ABC de insumos e de serviços com %, % acumulado e faixas A/B/C', function () {
    ($this->add)('composition', '97141', '10');
    ($this->add)('resource', '11270', '3');

    $abc = ($this->in)(fn () => app(AbcCurve::class)->resources($this->v));
    expect(array_column($abc, 'code'))->toBe(['4750', '99999', '11270', '20078'])
        ->and($abc[0])->toMatchArray(['quantity' => '0.3200', 'total' => '395.06', 'class' => 'A'])
        ->and($abc[1])->toMatchArray(['quantity' => '15.0000', 'total' => '30.00', 'class' => 'B'])
        ->and($abc[2]['total'])->toBe('7.98')->and($abc[3]['total'])->toBe('1.46')
        ->and(end($abc)['cumulative_percent'])->toBe('100.0000');

    $serv = ($this->in)(fn () => app(AbcCurve::class)->services($this->v));
    expect(array_column($serv, 'code'))->toBe(['97141', '11270'])->and(array_column($serv, 'total'))->toBe(['38.40', '7.98'])
        ->and(array_column($serv, 'class'))->toBe(['A', 'B']); // o 2º começa em 82,8% (< 95%)
});

it('item de insumo usa o preço publicado; insumo sem preço é recusado; código inexistente → não encontrado', function () {
    $item = ($this->add)('resource', '11270', '3');
    expect($item->unit_cost)->toBe('2.66')->and($item->total_cost)->toBe('7.98')
        ->and(($this->in)(fn () => BudgetItemResource::query()->where('budget_item_id', $item->id)->sole()->coefficient))->toBe('1.0000000')
        ->and(fn () => ($this->add)('resource', '99999', '1'))->toThrow(DomainRuleException::class)
        ->and(fn () => ($this->add)('composition', '00000', '1'))->toThrow(ModelNotFoundException::class);
});

it('base com outro regime de encargos ou outra UF é recusada (D-P03)', function () {
    $outra = DB::table('reference_datasets')->insertGetId(['source_id' => $this->ds->source_id, 'version' => '2024-12', 'state' => 'MT', 'charges_regime' => 'without_relief', 'status' => 'active']);
    $sp = DB::table('reference_datasets')->insertGetId(['source_id' => $this->ds->source_id, 'version' => '2024-12', 'state' => 'SP', 'charges_regime' => 'with_relief', 'status' => 'active']);

    foreach ([$outra, $sp] as $id) {
        expect(fn () => ($this->in)(fn () => $this->svc->addReferenceItem($this->v, ['reference_dataset_id' => $id, 'type' => 'resource', 'code' => '11270', 'group_id' => $this->g->id, 'quantity' => '1'], $this->user)))
            ->toThrow(DomainRuleException::class);
    }
    DB::table('reference_datasets')->where('id', $this->ds->id)->update(['status' => 'importing']);
    expect(fn () => ($this->add)('resource', '11270', '1'))->toThrow(ModelNotFoundException::class);
});

it('alterar o preço de um item oficial o torna "custom"; excluir o item remove composição e insumos', function () {
    $item = ($this->add)('composition', '97141', '10');
    $item = ($this->in)(fn () => $this->svc->updateItem($item, ['unit_cost' => '4.00'], $this->user));
    expect($item->source)->toBe('custom')->and($item->total_cost)->toBe('40.00')->and($item->source_ref['code'])->toBe('97141');

    ($this->in)(fn () => $this->svc->deleteItem($item, $this->user));
    expect(($this->in)(fn () => [BudgetItem::query()->count(), BudgetComposition::query()->count(), BudgetCompositionItem::query()->count(), BudgetItemResource::query()->count()]))
        ->toBe([0, 0, 0, 0]);
});

it('nova versão leva composições e insumos; a aprovada não aceita novos itens; base substituída segue rastreável', function () {
    ($this->add)('composition', '97141', '10');
    DB::table('budget_versions')->where('id', $this->v->id)->update(['status' => 'approved', 'locked_at' => now()]);
    expect(fn () => ($this->add)('resource', '11270', '1'))->toThrow(VersionLockedException::class);

    $v2 = ($this->in)(fn () => $this->svc->newVersion($this->budget->fresh(), $this->user));
    [$c1, $c2] = ($this->in)(fn () => [
        BudgetItemResource::query()->where('budget_version_id', $this->v->id)->count(),
        BudgetItemResource::query()->where('budget_version_id', $v2->id)->count(),
    ]);
    expect($c2)->toBe($c1)->and($c2)->toBe(3)
        ->and(($this->in)(fn () => BudgetCompositionItem::query()->where('budget_version_id', $v2->id)->count()))->toBe(3)
        ->and(($this->in)(fn () => app(AbcCurve::class)->resources($v2)))->toEqual(($this->in)(fn () => app(AbcCurve::class)->resources($this->v)));

    DB::table('reference_datasets')->where('id', $this->ds->id)->update(['status' => 'superseded']);
    expect(($this->in)(fn () => $this->svc->addReferenceItem($v2, ['reference_dataset_id' => $this->ds->id, 'type' => 'resource', 'code' => '11270', 'group_id' => BudgetItem::query()->where('budget_version_id', $v2->id)->value('group_id'), 'quantity' => '1'], $this->user))->unit_cost)->toBe('2.66');
});

it('banco: composição copiada e insumos finais só de item da MESMA versão; tipos no domínio', function () {
    $other = ($this->in)(fn () => $this->svc->create(['company_id' => $this->budget->company_id, 'code' => 'ORC-2', 'title' => 'x', 'mode' => 'manual'], $this->user));
    $item = ($this->add)('resource', '11270', '1');
    $base = ['tenant_id' => $this->A->id, 'budget_item_id' => $item->id, 'code' => 'X', 'description' => 'x', 'unit_code' => 'UN'];

    expect(Fx::errno(fn () => DB::table('budget_compositions')->insert($base + ['budget_version_id' => $other->current_version_id, 'unit_cost' => 1, 'source_snapshot' => '{}'])))->toBe(1452)
        ->and(Fx::errno(fn () => DB::table('budget_item_resources')->insert($base + ['budget_version_id' => $other->current_version_id, 'coefficient' => 1])))->toBe(1452)
        ->and(Fx::errno(fn () => DB::table('budget_item_resources')->insert(array_merge($base, ['budget_version_id' => $this->v->id, 'code' => 'Y', 'coefficient' => 1, 'kind' => 'tool']))))->toBe(3819)
        ->and(Fx::errno(fn () => DB::table('budget_item_resources')->insert(array_merge($base, ['budget_version_id' => $this->v->id, 'code' => '11270', 'coefficient' => 1]))))->toBe(1062);
});
