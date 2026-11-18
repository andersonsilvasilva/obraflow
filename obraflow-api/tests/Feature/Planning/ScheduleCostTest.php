<?php

use App\Models\Schedule;
use App\Models\ScheduleTaskItem;
use App\Modules\Planning\Services\ScheduleCostCalculator;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;

/*
 * Fase 6B — custo e venda do planejamento (FASE6B_MODELO.md, D-P72/D-P91/D-P92). Valores conferidos à mão com
 * aritmética decimal (meio-para-cima), recurso a recurso. Dados "DEMO".
 */

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->admin = Scenario::member($this->A, 'admin');
    $this->b = new Browser($this);
    $this->b->login($this->admin->email)->assertOk();
    $co = $this->b->post('/api/v1/companies', ['legal_name' => 'DEMO Construtora', 'document_number' => '11222333000181'])->json('data.uuid');
    $this->obra = $this->b->post('/api/v1/projects', ['company_uuid' => $co, 'name' => 'DEMO Obra', 'start_date' => '2026-03-02'])->assertCreated()->json('data.uuid');
    $this->url = "/api/v1/projects/{$this->obra}/schedule";
    $this->b->post($this->url, ['origin' => 'blank'])->assertCreated();
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->A, $fn, $this->admin);

    // S (etapa) ⊃ A (12,5 m²); B (sem quantidade) na raiz
    $tasks = $this->b->post("{$this->url}/tasks", ['name' => 'DEMO Estrutura', 'kind' => 'summary'])->assertCreated()->json('data.tasks');
    $s = collect($tasks)->firstWhere('name', 'DEMO Estrutura')['id'];
    $tasks = $this->b->post("{$this->url}/tasks", ['name' => 'DEMO Laje', 'parent_id' => $s, 'planned_start' => '2026-03-02', 'duration_days' => 5, 'unit_code' => 'M2', 'quantity' => '12.5'])->json('data.tasks');
    $a = collect($tasks)->firstWhere('name', 'DEMO Laje')['id'];
    $tasks = $this->b->post("{$this->url}/tasks", ['name' => 'DEMO Limpeza', 'planned_start' => '2026-03-02', 'duration_days' => 2])->json('data.tasks');
    $bId = collect($tasks)->firstWhere('name', 'DEMO Limpeza')['id'];
    $this->ids = compact('s', 'a') + ['b' => $bId];

    $this->schedule = ($this->in)(function () {
        $sch = Schedule::query()->sole();
        DB::table('schedules')->where('id', $sch->id)->update(['bdi_percent' => '25', 'discount_percent' => '3']);

        return $sch->refresh();
    });
    $this->item = fn (int $task, array $data) => ($this->in)(fn () => ScheduleTaskItem::query()->forceCreate($data + [
        'schedule_id' => $this->schedule->id, 'task_id' => $task, 'unit_code' => 'UN', 'cost_material' => 0, 'cost_labor' => 0, 'cost_equipment' => 0,
        'cost_service' => 0, 'cost_other' => 0,
    ]));
});

it('calcula recurso a recurso (D-P72): consumo × quantidade da atividade, BDI e desconto do planejamento ou do recurso, por tipo', function () {
    ($this->item)($this->ids['a'], ['source' => 'catalog', 'kind' => 'material', 'description' => 'DEMO Cimento', 'quantity_per_unit' => '2', 'unit_cost' => '36.00', 'cost_material' => '36.00']);
    ($this->item)($this->ids['a'], ['source' => 'sinapi_composition', 'kind' => 'composition', 'description' => 'DEMO Composição', 'quantity_per_unit' => '1', 'unit_cost' => '3.84',
        'cost_labor' => '1.40', 'cost_material' => '2.10', 'cost_equipment' => '0.34']);
    ($this->item)($this->ids['a'], ['source' => 'manual', 'kind' => 'other', 'description' => 'DEMO Outro', 'quantity_per_unit' => '0.3333333', 'unit_cost' => '10.00', 'cost_other' => '10.00', 'bdi_percent' => '30']);
    ($this->item)($this->ids['b'], ['source' => 'catalog', 'kind' => 'service', 'description' => 'DEMO Limpeza final', 'quantity_per_unit' => '1', 'unit_cost' => '350.00', 'cost_service' => '350.00']);

    $r = ($this->in)(fn () => app(ScheduleCostCalculator::class)->compute($this->schedule->fresh()));
    $items = array_values($r['items']);

    // cimento: 12,5 × 2 = 25 sc → 900,00; com BDI 1.125,00; venda × 0,97 = 1.091,25
    expect($items[0])->toMatchArray(['quantity_total' => '25.0000', 'cost' => '900.00', 'with_bdi' => '1125.00', 'sale' => '1091.25', 'unit_sale' => '43.65'])
        // composição: 48,00 → 60,00 → 58,20; tipos somam exatamente (21,22 + 31,83 + 5,15)
        ->and($items[1]['kinds'])->toMatchArray(['labor' => ['cost' => '17.50', 'sale' => '21.22'], 'material' => ['cost' => '26.25', 'sale' => '31.83'], 'equipment' => ['cost' => '4.25', 'sale' => '5.15']])
        ->and($items[1]['sale'])->toBe('58.20')
        // BDI próprio de 30%: 41,67 → 54,17 → 52,54 (a parcela "outros" é ajustada em 1 centavo para fechar)
        ->and($items[2])->toMatchArray(['cost' => '41.67', 'with_bdi' => '54.17', 'sale' => '52.54', 'bdi_percent' => '30.0000'])
        ->and($items[2]['kinds']['other'])->toBe(['cost' => '41.67', 'sale' => '52.54'])
        // atividade sem quantidade conta como 1
        ->and($items[3])->toMatchArray(['quantity_total' => '1.0000', 'cost' => '350.00', 'sale' => '424.38']);

    $a = $r['tasks'][$this->ids['a']];
    expect($a)->toMatchArray(['cost' => '989.67', 'with_bdi' => '1239.17', 'sale' => '1201.99', 'item_count' => 3, 'unit_cost' => '79.17', 'unit_sale' => '96.16'])
        ->and($a['unit_kinds']['material'])->toBe(['cost' => '74.10', 'sale' => '89.85'])
        ->and($r['tasks'][$this->ids['s']])->toMatchArray(['cost' => '989.67', 'sale' => '1201.99', 'unit_cost' => null]) // etapa = soma das filhas
        ->and($r['totals'])->toMatchArray(['cost' => '1339.67', 'with_bdi' => '1676.67', 'bdi_amount' => '337.00', 'discount_amount' => '50.30', 'sale' => '1626.37', 'item_count' => 4])
        ->and($r['totals']['by_kind'])->toBe([
            'material' => ['cost' => '926.25', 'sale' => '1123.08'], 'labor' => ['cost' => '17.50', 'sale' => '21.22'], 'equipment' => ['cost' => '4.25', 'sale' => '5.15'],
            'service' => ['cost' => '350.00', 'sale' => '424.38'], 'other' => ['cost' => '41.67', 'sale' => '52.54'],
        ]);
});

it('planejamento sem recursos: tudo zero', function () {
    $r = ($this->in)(fn () => app(ScheduleCostCalculator::class)->compute($this->schedule->fresh()));
    expect($r['totals'])->toMatchArray(['cost' => '0.00', 'sale' => '0.00', 'item_count' => 0])
        ->and($r['tasks'][$this->ids['a']]['unit_cost'])->toBeNull();
});

it('banco: tipos somam o custo unitário; valores e percentuais no domínio; atividade do mesmo planejamento', function () {
    $base = ['source' => 'manual', 'kind' => 'material', 'description' => 'DEMO', 'quantity_per_unit' => '1', 'unit_cost' => '10.00'];
    expect(fn () => ($this->item)($this->ids['a'], $base + ['cost_material' => '9.99']))->toThrow(QueryException::class)     // não soma
        ->and(fn () => ($this->item)($this->ids['a'], $base + ['cost_material' => '10.00', 'discount_percent' => '100']))->toThrow(QueryException::class)
        ->and(fn () => ($this->item)($this->ids['a'], ['kind' => 'tool'] + $base + ['cost_material' => '10.00']))->toThrow(QueryException::class)
        ->and(fn () => ($this->item)($this->ids['a'], ['source' => 'x'] + $base + ['cost_material' => '10.00']))->toThrow(QueryException::class);

    // atividade de OUTRO planejamento (outra obra do mesmo tenant): a FK composta recusa
    $co = $this->b->post('/api/v1/companies', ['legal_name' => 'DEMO Outra', 'document_number' => '11444777000161'])->json('data.uuid');
    $obra2 = $this->b->post('/api/v1/projects', ['company_uuid' => $co, 'name' => 'DEMO Obra 2', 'start_date' => '2026-03-02'])->json('data.uuid');
    $this->b->post("/api/v1/projects/{$obra2}/schedule", ['origin' => 'blank'])->assertCreated();
    $t2 = collect($this->b->post("/api/v1/projects/{$obra2}/schedule/tasks", ['name' => 'DEMO X', 'planned_start' => '2026-03-02', 'duration_days' => 1])->json('data.tasks'))->first()['id'];
    expect(fn () => ($this->item)($t2, $base + ['cost_material' => '10.00']))->toThrow(QueryException::class);

    ($this->item)($this->ids['a'], $base + ['cost_material' => '10.00']); // válido
    expect(($this->in)(fn () => ScheduleTaskItem::query()->count()))->toBe(1);
});
