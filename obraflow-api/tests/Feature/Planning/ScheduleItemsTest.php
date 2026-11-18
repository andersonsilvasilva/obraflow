<?php

use App\Models\AuditLog;
use App\Models\ReferenceDataset;
use App\Modules\Audit\AuditEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;
use Tests\Support\SinapiFixture;

/*
 * Fase 6B — recursos das atividades pela API (D-P87…D-P97): catálogo, SINAPI, composição própria, digitado e orçamento;
 * BDI/desconto do planejamento; regras (só em atividade, etapa não recebe, regime da obra). Dados "DEMO".
 */

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    Artisan::call('obraflow:references:import', ['--competency' => '2024-12', '--state' => 'MT', '--regime' => 'with_relief',
        '--resources' => SinapiFixture::insumos(), '--compositions' => SinapiFixture::analitico(), '--families' => SinapiFixture::familias(), '--activate' => true]);
    $this->ds = ReferenceDataset::query()->sole()->id;
    $this->A = Scenario::tenant();
    $this->admin = Scenario::member($this->A, 'admin');
    $this->b = new Browser($this);
    $this->b->login($this->admin->email)->assertOk();
    $this->co = $this->b->post('/api/v1/companies', ['legal_name' => 'DEMO Construtora', 'document_number' => '11222333000181'])->json('data.uuid');
    $this->obra = $this->b->post('/api/v1/projects', ['company_uuid' => $this->co, 'name' => 'DEMO Obra', 'start_date' => '2026-03-02', 'social_charges_regime' => 'with_relief'])
        ->assertCreated()->json('data.uuid');
    $this->url = "/api/v1/projects/{$this->obra}/schedule";
    $this->m2 = $this->b->post('/api/v1/units', ['code' => 'M2', 'name' => 'metro quadrado'])->assertCreated()->json('data.id');
    $this->sc = $this->b->post('/api/v1/units', ['code' => 'SC', 'name' => 'saco'])->assertCreated()->json('data.id');
});

function planejamento(object $t): array
{
    $t->b->post($t->url, ['origin' => 'blank'])->assertCreated();
    $tasks = $t->b->post("{$t->url}/tasks", ['name' => 'DEMO Estrutura', 'kind' => 'summary'])->json('data.tasks');
    $s = collect($tasks)->firstWhere('name', 'DEMO Estrutura')['id'];
    $tasks = $t->b->post("{$t->url}/tasks", ['name' => 'DEMO Contrapiso', 'parent_id' => $s, 'planned_start' => '2026-03-02', 'duration_days' => 3, 'unit_code' => 'M2', 'quantity' => '10'])->json('data.tasks');

    return ['s' => $s, 'a' => collect($tasks)->firstWhere('name', 'DEMO Contrapiso')['id']];
}

it('lança do catálogo (vários de uma vez), digitado, composição própria (regime da obra) e SINAPI; totais no planejamento', function () {
    ['s' => $s, 'a' => $a] = planejamento($this);
    $cim = $this->b->post('/api/v1/resources', ['kind' => 'material', 'name' => 'DEMO Cimento', 'unit_id' => $this->sc, 'price' => '36.00'])->json('data.uuid');
    $are = $this->b->post('/api/v1/resources', ['kind' => 'material', 'name' => 'DEMO Areia', 'unit_id' => $this->sc, 'price' => '8.50'])->json('data.uuid');
    $own = $this->b->post('/api/v1/own-compositions', ['name' => 'DEMO Contrapiso 5 cm', 'unit_id' => $this->m2, 'reference_date' => '2026-11-01',
        'material_value' => '18.40', 'labor_value_relief' => '14.50', 'labor_value_no_relief' => '16.75', 'equipment_value_relief' => '1.20'])->json('data.uuid');

    $this->b->post("{$this->url}/tasks/{$a}/items", ['source' => 'catalog', 'resource_uuids' => [$cim, $are], 'quantity_per_unit' => '0.5'])->assertCreated();
    $this->b->post("{$this->url}/tasks/{$a}/items", ['source' => 'manual', 'kind' => 'service', 'description' => 'DEMO Frete', 'unit_code' => 'VB', 'unit_cost' => '120.00', 'quantity_per_unit' => '0.1'])->assertCreated();
    $this->b->post("{$this->url}/tasks/{$a}/items", ['source' => 'own_composition', 'own_composition_uuid' => $own])->assertCreated();
    $d = $this->b->post("{$this->url}/tasks/{$a}/items", ['source' => 'sinapi_composition', 'reference_dataset_id' => $this->ds, 'code' => '97141'])->assertCreated()->json('data');

    $items = collect($d['items'])->keyBy('description');
    expect($d['items'])->toHaveCount(5)
        ->and($items['DEMO Cimento'])->toMatchArray(['source' => 'catalog', 'kind' => 'material', 'unit_code' => 'SC', 'unit_cost' => '36.00', 'quantity_per_unit' => '0.5000000'])
        ->and($items['DEMO Cimento']['computed'])->toMatchArray(['quantity_total' => '5.0000', 'cost' => '180.00'])
        // composição própria: valores do regime desonerado da obra (18,40 + 14,50 + 1,20)
        ->and($items['DEMO Contrapiso 5 cm'])->toMatchArray(['kind' => 'composition', 'unit_cost' => '34.10'])
        ->and($items['DEMO Contrapiso 5 cm']['costs'])->toMatchArray(['material' => '18.40', 'labor' => '14.50', 'equipment' => '1.20'])
        ->and($items['DEMO Contrapiso 5 cm']['source_ref']['regime'])->toBe('with_relief');
    $sinapi = collect($d['items'])->firstWhere('source', 'sinapi_composition');
    expect($sinapi)->toMatchArray(['code' => '97141', 'unit_cost' => '3.84', 'kind' => 'composition'])
        ->and(bcadd(array_sum(array_map(fn ($v) => (float) $v, $sinapi['costs'])), '0', 2))->toBe('3.84')
        ->and($sinapi['breakdown'])->toHaveCount(3);

    // atividade: 180 + 42,50 + 120 + 341 + 38,40 = 721,90 (sem BDI); a etapa soma a filha
    $task = collect($d['tasks'])->firstWhere('id', $a);
    expect($task['cost'])->toMatchArray(['cost' => '721.90', 'sale' => '721.90', 'item_count' => 5])
        ->and(collect($d['tasks'])->firstWhere('id', $s)['cost']['cost'])->toBe('721.90')
        ->and($d['costs'])->toMatchArray(['cost' => '721.90', 'sale' => '721.90'])
        ->and($d['schedule']['regime'])->toBe('with_relief');

    // BDI pela fórmula do TCU e desconto: venda recalculada
    $p = $this->b->put("{$this->url}/pricing", ['bdi_method' => 'tcu', 'bdi_components' => ['ac' => '4', 's' => '0.8', 'r' => '1.27', 'g' => '0', 'df' => '1.23', 'l' => '7.4', 'i' => '8.65'], 'discount_percent' => '2'])
        ->assertOk()->json('data');
    expect($p['schedule'])->toMatchArray(['bdi_percent' => '26.2400', 'discount_percent' => '2.0000'])
        ->and($p['costs']['bdi_amount'])->not->toBe('0.00')
        ->and(bccomp($p['costs']['sale'], $p['costs']['cost'], 2))->toBe(1);
});

it('regras: só em atividade; atividade com recursos não vira etapa; excluir a atividade leva os recursos; cópia não editável', function () {
    ['s' => $s, 'a' => $a] = planejamento($this);
    $manual = ['source' => 'manual', 'kind' => 'material', 'description' => 'DEMO Tinta', 'unit_code' => 'L', 'unit_cost' => '25.00'];

    $this->b->post("{$this->url}/tasks/{$s}/items", $manual)->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');
    $d = $this->b->post("{$this->url}/tasks/{$a}/items", $manual)->assertCreated()->json('data');
    $id = $d['items'][0]['id'];

    // virar etapa com recursos: recusado
    $this->b->post("{$this->url}/tasks", ['name' => 'DEMO Sub', 'parent_id' => $a, 'planned_start' => '2026-03-02', 'duration_days' => 1])->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');

    // digitado: edita custo, descrição e consumo; o BDI próprio vale só para ele
    $u = $this->b->put("{$this->url}/items/{$id}", ['unit_cost' => '30.00', 'quantity_per_unit' => '2', 'bdi_percent' => '10'])->assertOk()->json('data.items.0');
    expect($u)->toMatchArray(['unit_cost' => '30.00', 'quantity_per_unit' => '2.0000000', 'bdi_percent' => '10.0000'])
        ->and($u['computed'])->toMatchArray(['cost' => '600.00', 'with_bdi' => '660.00']);

    // cópia (catálogo/SINAPI…) não muda preço nem descrição
    $own = $this->b->post('/api/v1/own-compositions', ['name' => 'DEMO X', 'unit_id' => $this->m2, 'reference_date' => '2026-11-01', 'material_value' => '1.00'])->json('data.uuid');
    $copy = collect($this->b->post("{$this->url}/tasks/{$a}/items", ['source' => 'own_composition', 'own_composition_uuid' => $own])->json('data.items'))->firstWhere('source', 'own_composition')['id'];
    $this->b->put("{$this->url}/items/{$copy}", ['unit_cost' => '9.00'])->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');

    $this->b->delete("{$this->url}/items/{$copy}")->assertOk();
    $this->b->delete("{$this->url}/tasks/{$a}")->assertOk();
    expect($this->b->get($this->url)->json('data.items'))->toBe([]);
});

it('SINAPI: base com outro regime é recusada; obra sem encargos não usa SINAPI; permissões por origem', function () {
    ['a' => $a] = planejamento($this);
    $this->b->put("/api/v1/projects/{$this->obra}", ['social_charges_regime' => 'without_relief'])->assertOk();
    $this->b->post("{$this->url}/tasks/{$a}/items", ['source' => 'sinapi_composition', 'reference_dataset_id' => $this->ds, 'code' => '97141'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');
    $this->b->put("/api/v1/projects/{$this->obra}", ['social_charges_regime' => 'not_applicable'])->assertOk();
    $this->b->post("{$this->url}/tasks/{$a}/items", ['source' => 'sinapi_resource', 'reference_dataset_id' => $this->ds, 'code' => '11270'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');

    // member não edita o planejamento
    $m = new Browser($this);
    $m->login(Scenario::member($this->A, 'member')->email)->assertOk();
    $m->post("{$this->url}/tasks/{$a}/items", ['source' => 'manual', 'kind' => 'material', 'description' => 'x', 'unit_code' => 'UN', 'unit_cost' => '1'])->assertForbidden();
});

it('do orçamento aprovado (D-P93): cada atividade recebe o item como recurso, com o BDI global; auditado', function () {
    $orc = $this->b->post('/api/v1/budgets', ['company_uuid' => $this->co, 'project_uuid' => $this->obra, 'code' => 'ORC-1', 'title' => 'DEMO', 'mode' => 'reference', 'reference_dataset_id' => $this->ds])
        ->assertCreated()->json('data.uuid');
    $base = "/api/v1/budgets/{$orc}/versions/1";
    $g = $this->b->post("{$base}/groups", ['code' => '1', 'name' => 'DEMO Rede'])->assertCreated()->json('data.id');
    $this->b->post("{$base}/items/reference", ['reference_dataset_id' => $this->ds, 'type' => 'composition', 'code' => '97141', 'group_id' => $g, 'quantity' => '10'])->assertCreated();
    $this->b->post("{$base}/items", ['group_id' => $g, 'code' => '1.99', 'description' => 'DEMO Item manual', 'unit_code' => 'VB', 'quantity' => '1', 'unit_cost' => '100.00'])->assertCreated();
    $this->b->put("{$base}/bdi", ['scope' => 'global', 'method' => 'informed', 'percent' => '20'])->assertOk();
    foreach (['submit', 'approve'] as $act) {
        $this->b->post("/api/v1/budgets/{$orc}/workflow/{$act}")->assertOk();
    }

    $d = $this->b->post($this->url, ['origin' => 'budget', 'budget_uuid' => $orc, 'version' => 1])->assertCreated()->json('data');
    expect($d['items'])->toHaveCount(2)
        ->and(collect($d['items'])->pluck('source')->unique()->all())->toBe(['budget'])
        ->and(collect($d['items'])->firstWhere('code', '97141'))->toMatchArray(['unit_cost' => '3.84', 'quantity_per_unit' => '1.0000000'])
        ->and(collect($d['items'])->firstWhere('code', '1.99')['costs']['other'])->toBe('100.00') // item sem insumos: tudo em "outros"
        ->and($d['schedule']['bdi_percent'])->toBe('20.0000')
        ->and($d['costs']['cost'])->toBe('138.40') // 10 × 3,84 + 100,00
        ->and(AuditLog::query()->where('event', AuditEvent::SCHEDULE_COSTS_IMPORTED)->count())->toBe(1);

    // "Trazer custos do orçamento" de novo não duplica (só atividades sem recursos)
    expect($this->b->post("{$this->url}/items/from-budget")->assertOk()->json('data.items'))->toHaveCount(2);
});
