<?php

use App\Models\AuditLog;
use App\Modules\Audit\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;
use Tests\Support\XlsxFixture;

/* Fase 6A — planejamento da obra (FASE6_MODELO.md, D-P65…D-P71). Datas conferidas à mão. Dados "DEMO". */

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->admin = Scenario::member($this->A, 'admin');
    $this->b = new Browser($this);
    $this->b->login($this->admin->email)->assertOk();
    $this->co = $this->b->post('/api/v1/companies', ['legal_name' => 'DEMO Construtora', 'document_number' => '11222333000181'])->json('data.uuid');
    // 02/03/2026 é segunda-feira
    $this->obra = $this->b->post('/api/v1/projects', ['company_uuid' => $this->co, 'name' => 'DEMO Obra', 'start_date' => '2026-03-02'])->assertCreated()->json('data.uuid');
    $this->url = "/api/v1/projects/{$this->obra}/schedule";
});

/** Cria planejamento em branco e devolve o id da atividade criada por nome. */
function tarefa(object $t, array $data): int
{
    $tasks = $t->b->post("{$t->url}/tasks", $data)->assertCreated()->json('data.tasks');

    return collect($tasks)->firstWhere('name', $data['name'])['id'];
}

function cronograma(object $t, string $query = ''): array
{
    return $t->b->get($t->url.$query)->assertOk()->json('data');
}

it('dias úteis pela obra (D-P66): horário seg–sex e feriado; feriado novo move o término na hora', function () {
    $this->b->get($this->url)->assertOk()->assertJsonPath('data.schedule', null)->assertJsonPath('data.abilities.edit', true);
    $this->b->post($this->url, ['origin' => 'blank'])->assertCreated()->assertJsonPath('data.schedule.origin', 'blank');
    $this->b->post($this->url, ['origin' => 'blank'])->assertUnprocessable(); // um por obra
    expect(cronograma($this)['schedule']['duration_mode'])->toBe('calendar'); // padrão: dias corridos (A-51)
    $this->b->put($this->url, ['duration_mode' => 'working'])->assertOk()->assertJsonPath('data.schedule.duration_mode', 'working');

    $semana = array_map(fn ($d) => in_array($d, [1, 2, 3, 4, 5], true) ? ['start_1' => '07:00', 'end_1' => '12:00', 'start_2' => '13:00', 'end_2' => '17:00'] : [], range(0, 6));
    $this->b->put("/api/v1/projects/{$this->obra}/work-schedule", ['days' => $semana])->assertOk();

    $a = tarefa($this, ['name' => 'DEMO Fundação', 'planned_start' => '2026-03-02', 'duration_days' => 5]);
    $row = collect(cronograma($this)['tasks'])->firstWhere('id', $a);
    expect($row)->toMatchArray(['start' => '2026-03-02', 'end' => '2026-03-06', 'duration_days' => 5, 'calendar_days' => 5]);

    // começa no sábado → vai para a segunda; 6 dias úteis atravessam o fim de semana
    $b = tarefa($this, ['name' => 'DEMO Alvenaria', 'planned_start' => '2026-03-07', 'duration_days' => 6]);
    expect(collect(cronograma($this)['tasks'])->firstWhere('id', $b))->toMatchArray(['start' => '2026-03-09', 'end' => '2026-03-16', 'calendar_days' => 8]);

    $this->b->post("/api/v1/projects/{$this->obra}/calendar/exceptions", ['description' => 'DEMO Parada', 'date' => '2026-03-04', 'kind' => 'single'])->assertCreated();
    expect(collect(cronograma($this)['tasks'])->firstWhere('id', $a))->toMatchArray(['end' => '2026-03-09', 'duration_days' => 5]);

    // término informado → duração calculada em dias úteis
    $this->b->put("{$this->url}/tasks/{$a}", ['planned_end' => '2026-03-11'])->assertOk();
    expect(collect(cronograma($this)['tasks'])->firstWhere('id', $a))->toMatchArray(['duration_days' => 7]); // 2,3,5,6,9,10,11

    // dias corridos (A-51): a mesma duração vira 7 dias seguidos, feriado e fim de semana contam
    $this->b->put($this->url, ['duration_mode' => 'calendar'])->assertOk();
    expect(collect(cronograma($this)['tasks'])->firstWhere('id', $a))->toMatchArray(['start' => '2026-03-02', 'end' => '2026-03-08', 'duration_days' => 7]);
    $this->b->put($this->url, ['duration_mode' => 'x'])->assertUnprocessable();
});

it('dependências reprogramam (FS com atraso, SS, FF); caminho crítico e folga; ciclo recusado (D-P67)', function () {
    $this->b->post($this->url, ['origin' => 'blank'])->assertCreated();
    // sem horário: todo dia é útil
    $a = tarefa($this, ['name' => 'DEMO A', 'planned_start' => '2026-03-02', 'duration_days' => 5]);  // 02–06
    $c = tarefa($this, ['name' => 'DEMO C', 'planned_start' => '2026-03-02', 'duration_days' => 3]);
    $b = tarefa($this, ['name' => 'DEMO B', 'planned_start' => '2026-03-02', 'duration_days' => 2]);  // 02–03, solta
    $m = tarefa($this, ['name' => 'DEMO Entrega', 'kind' => 'milestone', 'planned_start' => '2026-03-02']);

    $this->b->post("{$this->url}/dependencies", ['predecessor_id' => $a, 'successor_id' => $c, 'type' => 'FS', 'lag_days' => 1])->assertCreated();
    $this->b->post("{$this->url}/dependencies", ['predecessor_id' => $c, 'successor_id' => $m])->assertCreated();
    $t = collect(cronograma($this)['tasks'])->keyBy('id');
    expect($t[$c])->toMatchArray(['start' => '2026-03-08', 'end' => '2026-03-10']) // 06 + 1 dia de atraso + 1
        ->and($t[$m])->toMatchArray(['start' => '2026-03-11', 'end' => '2026-03-11', 'duration_days' => 0])
        ->and([$t[$a]['critical'], $t[$c]['critical'], $t[$m]['critical'], $t[$b]['critical']])->toBe([true, true, true, false])
        ->and($t[$b]['float'])->toBe(7); // termina 03, o projeto termina 10 → 7 dias de folga (04 a 10)

    $this->b->post("{$this->url}/dependencies", ['predecessor_id' => $m, 'successor_id' => $a])->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule'); // ciclo
    $this->b->post("{$this->url}/dependencies", ['predecessor_id' => $a, 'successor_id' => $a])->assertUnprocessable();

    // SS +2 e FF
    $this->b->post("{$this->url}/dependencies", ['predecessor_id' => $a, 'successor_id' => $b, 'type' => 'SS', 'lag_days' => 2])->assertCreated();
    expect(collect(cronograma($this)['tasks'])->firstWhere('id', $b))->toMatchArray(['start' => '2026-03-04', 'end' => '2026-03-05']);
    $f = tarefa($this, ['name' => 'DEMO F', 'planned_start' => '2026-03-02', 'duration_days' => 2]);
    $this->b->post("{$this->url}/dependencies", ['predecessor_id' => $c, 'successor_id' => $f, 'type' => 'FF'])->assertCreated();
    expect(collect(cronograma($this)['tasks'])->firstWhere('id', $f))->toMatchArray(['start' => '2026-03-09', 'end' => '2026-03-10']);

    // aumentar A empurra C e o marco
    $this->b->put("{$this->url}/tasks/{$a}", ['duration_days' => 7])->assertOk();
    expect(collect(cronograma($this)['tasks'])->firstWhere('id', $m)['start'])->toBe('2026-03-13');
});

it('etapas: subetapas em níveis, EAP renumerada, etapa cobre as filhas e % ponderado; mover e excluir', function () {
    $this->b->post($this->url, ['origin' => 'blank'])->assertCreated();
    $e1 = tarefa($this, ['name' => 'DEMO Estrutura', 'kind' => 'summary']);
    $x = tarefa($this, ['name' => 'DEMO Formas', 'parent_id' => $e1, 'planned_start' => '2026-03-02', 'duration_days' => 2]);
    $y = tarefa($this, ['name' => 'DEMO Concretagem', 'parent_id' => $e1, 'planned_start' => '2026-03-05', 'duration_days' => 6]);
    $e2 = tarefa($this, ['name' => 'DEMO Acabamento', 'kind' => 'summary']);

    $this->b->put("{$this->url}/tasks/{$x}", ['percent_complete' => 100])->assertOk();
    $this->b->put("{$this->url}/tasks/{$e1}", ['percent_complete' => 50])->assertUnprocessable(); // etapa: calculado
    $t = collect(cronograma($this)['tasks'])->keyBy('id');
    expect($t[$e1])->toMatchArray(['wbs' => '1', 'start' => '2026-03-02', 'end' => '2026-03-10', 'percent' => '25.00']) // 100×2 / 8
        ->and([$t[$x]['wbs'], $t[$y]['wbs'], $t[$e2]['wbs']])->toBe(['1.1', '1.2', '2'])
        ->and(DB::table('schedule_progress_history')->where('task_id', $x)->value('source'))->toBe('manual');

    // "+" numa atividade: ela vira etapa (sem dependências)
    $z = tarefa($this, ['name' => 'DEMO Laje', 'parent_id' => $y, 'planned_start' => '2026-03-05', 'duration_days' => 1]);
    expect(collect(cronograma($this)['tasks'])->firstWhere('id', $y))->toMatchArray(['kind' => 'summary']);
    expect(collect(cronograma($this)['tasks'])->firstWhere('id', $z)['wbs'])->toBe('1.2.1');

    $this->b->post("{$this->url}/tasks/{$y}/move", ['parent_id' => $e2, 'position' => 0])->assertOk();
    $t = collect(cronograma($this)['tasks'])->keyBy('id');
    expect([$t[$y]['wbs'], $t[$z]['wbs']])->toBe(['2.1', '2.1.1']);
    $this->b->post("{$this->url}/tasks/{$e2}/move", ['parent_id' => $z, 'position' => 0])->assertUnprocessable(); // dentro dela mesma

    $this->b->delete("{$this->url}/tasks/{$x}")->assertUnprocessable(); // tem avanço registrado
    $this->b->delete("{$this->url}/tasks/{$e2}")->assertOk(); // etapa e tudo abaixo
    expect(collect(cronograma($this)['tasks'])->pluck('name')->all())->toBe(['DEMO Estrutura', 'DEMO Formas']);
});

it('cria do orçamento APROVADO da obra: etapas viram etapas, itens viram atividades com quantidade (D-P68)', function () {
    $orc = $this->b->post('/api/v1/budgets', ['company_uuid' => $this->co, 'project_uuid' => $this->obra, 'title' => 'DEMO', 'mode' => 'manual'])->json('data.uuid');
    $base = "/api/v1/budgets/{$orc}/versions/1";
    $g = $this->b->post("{$base}/groups", ['code' => '1', 'name' => 'DEMO Infraestrutura'])->json('data.id');
    $g2 = $this->b->post("{$base}/groups", ['code' => '1.1', 'name' => 'DEMO Fundações', 'parent_id' => $g])->json('data.id');
    $this->b->post("{$base}/items", ['group_id' => $g2, 'code' => '1.1.1', 'description' => 'DEMO Estaca', 'unit_code' => 'm', 'quantity' => '120', 'unit_cost' => '80'])->assertCreated();
    $this->b->post("{$base}/items", ['group_id' => $g, 'code' => '1.2', 'description' => 'DEMO Baldrame', 'unit_code' => 'm³', 'quantity' => '12.5', 'unit_cost' => '900'])->assertCreated();

    $this->b->post($this->url, ['origin' => 'budget', 'budget_uuid' => $orc, 'version' => 1])->assertUnprocessable(); // não aprovado
    $this->b->post("/api/v1/budgets/{$orc}/workflow/submit")->assertOk();
    $this->b->post("/api/v1/budgets/{$orc}/workflow/approve")->assertOk();

    $data = $this->b->post($this->url, ['origin' => 'budget', 'budget_uuid' => $orc, 'version' => 1])->assertCreated()->json('data');
    expect($data['schedule']['origin'])->toBe('budget')->and($data['schedule']['budget']['version'])->toBe(1);
    $t = collect($data['tasks']);
    expect($t->pluck('name')->all())->toBe(['DEMO Infraestrutura', 'DEMO Fundações', 'DEMO Estaca', 'DEMO Baldrame'])
        ->and($t->pluck('kind')->all())->toBe(['summary', 'summary', 'task', 'task'])
        ->and($t->pluck('wbs')->all())->toBe(['1', '1.1', '1.1.1', '1.2'])
        ->and($t->firstWhere('name', 'DEMO Estaca'))->toMatchArray(['quantity' => '120.0000', 'unit_code' => 'm', 'from_budget' => true, 'start' => '2026-03-02']);
});

it('linhas de base (até 10) congelam as datas e mostram o desvio; apagar exige não ter histórico (D-P71)', function () {
    $this->b->post($this->url, ['origin' => 'blank'])->assertCreated();
    $a = tarefa($this, ['name' => 'DEMO A', 'planned_start' => '2026-03-02', 'duration_days' => 5]);
    $this->b->post("{$this->url}/baselines", ['name' => 'DEMO Contratual'])->assertCreated()->assertJsonPath('data.baselines.0.number', 1);
    $this->b->put("{$this->url}/tasks/{$a}", ['duration_days' => 8])->assertOk();

    $row = collect(cronograma($this, '?baseline=1')['tasks'])->firstWhere('id', $a);
    expect($row['baseline'])->toBe(['start' => '2026-03-02', 'end' => '2026-03-06', 'delta_days' => 3]);
    expect(cronograma($this)['tasks'][0]['baseline'])->toBeNull();

    foreach (range(2, 10) as $n) {
        $this->b->post("{$this->url}/baselines", ['name' => "DEMO {$n}"])->assertCreated();
    }
    $this->b->post("{$this->url}/baselines", ['name' => 'DEMO 11'])->assertUnprocessable();
    $this->b->delete($this->url)->assertUnprocessable(); // tem linha de base

    // obra sem histórico: "Deletar tudo" funciona
    $obra2 = $this->b->post('/api/v1/projects', ['company_uuid' => $this->co, 'name' => 'DEMO Obra 2'])->json('data.uuid');
    $this->b->post("/api/v1/projects/{$obra2}/schedule", ['origin' => 'blank'])->assertCreated();
    $this->b->post("/api/v1/projects/{$obra2}/schedule/tasks", ['name' => 'x', 'kind' => 'summary'])->assertCreated();
    $this->b->delete("/api/v1/projects/{$obra2}/schedule")->assertNoContent();
    $this->b->get("/api/v1/projects/{$obra2}/schedule")->assertJsonPath('data.schedule', null);
});

it('acesso: ver exige acesso à obra; editar exige schedules.manage E nível edit; outro tenant não vê', function () {
    $this->b->post($this->url, ['origin' => 'blank'])->assertCreated();
    $member = Scenario::member($this->A, 'member'); // schedules.view, sem manage
    $mb = new Browser($this);
    $mb->login($member->email)->assertOk();
    $mb->get($this->url)->assertNotFound();
    $this->b->post("/api/v1/projects/{$this->obra}/access", ['user_uuid' => $member->uuid, 'access_level' => 'edit'])->assertOk();
    $mb->get($this->url)->assertOk()->assertJsonPath('data.abilities.edit', false);
    $mb->post("{$this->url}/tasks", ['name' => 'x'])->assertForbidden();

    $ob = new Browser($this);
    $ob->login(Scenario::member(Scenario::tenant(), 'admin')->email)->assertOk();
    $ob->get($this->url)->assertNotFound();
});

it('banco: marco com duração 0, dependência sem auto-ligação e % entre 0 e 100 (CHECKs); eventos da Fase 6 auditados', function () {
    $this->b->post($this->url, ['origin' => 'blank'])->assertCreated();
    $a = tarefa($this, ['name' => 'DEMO A', 'planned_start' => '2026-03-02', 'duration_days' => 2]);
    expect(fn () => DB::table('schedule_tasks')->where('id', $a)->update(['kind' => 'milestone']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('schedule_tasks')->where('id', $a)->update(['percent_complete' => 101]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('schedule_dependencies')->insert(['tenant_id' => $this->A->id, 'schedule_id' => DB::table('schedules')->value('id'),
            'predecessor_id' => $a, 'successor_id' => $a, 'type' => 'FS', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);

    $this->b->post("{$this->url}/baselines", ['name' => 'DEMO'])->assertCreated();
    $obra2 = $this->b->post('/api/v1/projects', ['company_uuid' => $this->co, 'name' => 'DEMO Obra 2'])->json('data.uuid');
    $this->b->post("/api/v1/projects/{$obra2}/schedule", ['origin' => 'blank'])->assertCreated();
    $this->b->delete("/api/v1/projects/{$obra2}/schedule")->assertNoContent();

    $emitidos = AuditLog::query()->distinct()->pluck('event')->all();
    // schedule.costs_imported é coberto em Feature/Planning/ScheduleItemsTest (6B)
    expect(array_values(array_diff(AuditEvent::phase6(), [AuditEvent::SCHEDULE_COSTS_IMPORTED], $emitidos)))->toBe([])
        ->and(AuditLog::query()->whereIn('event', AuditEvent::phase6())->pluck('tenant_id')->unique()->all())->toBe([$this->A->id])
        ->and(AuditLog::query()->where('event', AuditEvent::REGISTRY_CREATED)->where('entity_type', 'schedule_task')->exists())->toBeTrue();
});

it('desempenho: 500 atividades encadeadas com horário seg–sex são recalculadas rápido (risco da FASE6 §8)', function () {
    $this->b->post($this->url, ['origin' => 'blank'])->assertCreated();
    $semana = array_map(fn ($d) => in_array($d, [1, 2, 3, 4, 5], true) ? ['start_1' => '07:00', 'end_1' => '16:00'] : [], range(0, 6));
    $this->b->put("/api/v1/projects/{$this->obra}/work-schedule", ['days' => $semana])->assertOk();
    $this->b->put($this->url, ['duration_mode' => 'working'])->assertOk();
    $schedule = DB::table('schedules')->first();
    $now = now();
    $ids = [];
    foreach (range(1, 500) as $i) {
        $ids[] = DB::table('schedule_tasks')->insertGetId(['tenant_id' => $this->A->id, 'schedule_id' => $schedule->id, 'wbs_code' => (string) $i,
            'name' => "DEMO {$i}", 'kind' => 'task', 'planned_start' => '2026-03-02', 'planned_end' => '2026-03-02', 'duration_days' => 2,
            'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
    }
    $deps = [];
    foreach (array_slice($ids, 1) as $k => $id) {
        $deps[] = ['tenant_id' => $this->A->id, 'schedule_id' => $schedule->id, 'predecessor_id' => $ids[$k], 'successor_id' => $id, 'type' => 'FS', 'lag_days' => 0, 'created_at' => $now, 'updated_at' => $now];
    }
    DB::table('schedule_dependencies')->insert($deps);

    $t0 = microtime(true);
    $data = cronograma($this);
    $elapsed = microtime(true) - $t0;
    // 500 × 2 dias úteis = 1.000 dias úteis = 200 semanas: de 02/03/2026 a 28/12/2029 (sexta)
    expect($data['summary']['end'])->toBe('2029-12-28')->and($data['summary']['critical'])->toBe(500)->and($elapsed)->toBeLessThan(10.0);
});

function planilhaPlanejamento(array $rows): UploadedFile
{
    $head = ['A' => 'Tipo*', 'B' => 'Item*', 'C' => 'Descrição*', 'D' => 'Data de início (Planejado)', 'E' => 'Data de entrega (Planejado)',
        'F' => 'Início da Etapa (Executado)', 'G' => 'Conclusão da Etapa (Executado)', 'H' => 'Valor unit.', 'I' => 'Quantidade', 'J' => 'Und.'];
    $path = XlsxFixture::write([$head, ...$rows]);

    return new UploadedFile($path, 'planning.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

it('importa a planilha (D-P68b): pré-visualização sem gravar; etapas pela numeração; recursos na atividade (D-P94); datas DD/MM e MM/DD', function () {
    $rows = [
        ['A' => 'Etapa', 'B' => '1', 'C' => 'DEMO Estrutura', 'D' => '02/03/2026', 'E' => '13/03/2026'],
        ['A' => 'Etapa', 'B' => '1.1', 'C' => 'DEMO Formas', 'D' => '02/03/2026', 'E' => '04/03/2026', 'I' => '50', 'J' => 'm²'],
        ['A' => 'Material', 'B' => '1.1.1', 'C' => 'DEMO Madeira', 'H' => '30,00', 'I' => '10', 'J' => 'm²'],
        ['A' => 'Etapa', 'B' => '1-2', 'C' => 'DEMO Concreto', 'D' => '03/25/2026', 'E' => '2026-03-27', 'F' => '25/03/2026'],
        ['A' => 'Etapa', 'B' => '2', 'C' => 'DEMO Acabamento'],
    ];
    $this->b->post('/api/v1/units', ['code' => 'M2', 'name' => 'Metro quadrado'])->assertCreated(); // "m²" da planilha → M2 cadastrada
    $prev = $this->b->request('POST', "{$this->url}/import/preview", ['file' => planilhaPlanejamento($rows)])->assertOk()->json('data');
    expect($prev['summary'])->toBe(['total' => 5, 'ok' => 5, 'skip' => 0, 'resources' => 1, 'error' => 0])
        ->and(collect($prev['rows'])->pluck('task.kind')->all())->toBe(['summary', 'task', null, 'task', 'task'])
        ->and($prev['rows'][2]['resource'])->toMatchArray(['task' => '1.1', 'kind' => 'material', 'unit' => 'M2', 'unit_cost' => '30.00', 'quantity_per_unit' => '10'])
        ->and($prev['rows'][3]['task'])->toMatchArray(['wbs' => '1.2', 'start' => '2026-03-25', 'duration_days' => 3, 'actual_start' => '2026-03-25']) // 03/25 = MM/DD
        ->and($prev['rows'][4]['warnings'])->toContain('Sem data de início: usa a data inicial da obra.')
        ->and(DB::table('schedules')->count())->toBe(0); // nada gravado

    $this->b->request('POST', "{$this->url}/import", ['file' => planilhaPlanejamento($rows)])->assertCreated()->assertJsonPath('data.import.created', 4);
    $t = collect(cronograma($this)['tasks']);
    expect($t->pluck('wbs')->all())->toBe(['1', '1.1', '1.2', '2'])
        ->and($t->firstWhere('name', 'DEMO Formas'))->toMatchArray(['quantity' => '50.0000', 'unit_code' => 'M2', 'end' => '2026-03-04'])
        ->and($t->firstWhere('name', 'DEMO Estrutura'))->toMatchArray(['kind' => 'summary', 'start' => '2026-03-02', 'end' => '2026-03-27'])
        ->and(cronograma($this)['schedule']['origin'])->toBe('spreadsheet');
    // o recurso entrou na atividade 1.1: 50 m² × 10 × 30,00
    $item = cronograma($this)['items'][0];
    expect($item)->toMatchArray(['description' => 'DEMO Madeira', 'kind' => 'material', 'unit_cost' => '30.00', 'task_id' => $t->firstWhere('name', 'DEMO Formas')['id']])
        ->and($item['computed']['cost'])->toBe('15000.00');
    $this->b->request('POST', "{$this->url}/import", ['file' => planilhaPlanejamento($rows)])->assertUnprocessable(); // já tem planejamento

    // erros: item sem a mãe antes, data inválida, descrição vazia → nada é importado
    $obra2 = $this->b->post('/api/v1/projects', ['company_uuid' => $this->co, 'name' => 'DEMO Obra 2'])->json('data.uuid');
    $ruim = [['A' => 'Etapa', 'B' => '3.1', 'C' => 'x'], ['A' => 'Etapa', 'B' => 'A.B', 'C' => 'y'], ['A' => 'Etapa', 'B' => '4', 'C' => '', 'D' => '31/02/2026']];
    $p2 = $this->b->request('POST', "/api/v1/projects/{$obra2}/schedule/import/preview", ['file' => planilhaPlanejamento($ruim)])->assertOk()->json('data');
    expect($p2['summary']['error'])->toBe(3)
        ->and($p2['rows'][0]['errors'][0])->toContain('etapa-mãe (3)')
        ->and($p2['rows'][2]['errors'])->toContain('Descrição obrigatória.');
    $this->b->request('POST', "/api/v1/projects/{$obra2}/schedule/import", ['file' => planilhaPlanejamento($ruim)])->assertUnprocessable();
    $this->b->get("/api/v1/projects/{$obra2}/schedule")->assertJsonPath('data.schedule', null);

    // modelo para baixar (xlsx gerado pelo OpenSpout) é lido pelo próprio importador
    $resp = $this->b->get("{$this->url}/import/template")->assertOk();
    $file = $resp->baseResponse->getFile()->getPathname();
    $tpl = $this->b->request('POST', "/api/v1/projects/{$obra2}/schedule/import/preview", ['file' => new UploadedFile($file, 'modelo.xlsx', null, null, true)])->assertOk()->json('data.summary');
    expect($tpl)->toBe(['total' => 5, 'ok' => 5, 'skip' => 0, 'resources' => 1, 'error' => 0]); // o recurso do exemplo entra na atividade
});

it('importa num planejamento já criado e vazio (mesmo com linha de base); com atividades é recusado', function () {
    $this->b->post($this->url, ['origin' => 'blank'])->assertCreated();
    $this->b->post("{$this->url}/baselines", ['name' => 'DEMO vazia'])->assertCreated();
    $rows = [['A' => 'Etapa', 'B' => '1', 'C' => 'DEMO Fundação', 'D' => '02/03/2026', 'E' => '06/03/2026']];
    $this->b->request('POST', "{$this->url}/import", ['file' => planilhaPlanejamento($rows)])->assertCreated()->assertJsonPath('data.import.created', 1);
    $data = cronograma($this);
    expect($data['schedule']['origin'])->toBe('spreadsheet')->and($data['tasks'])->toHaveCount(1)->and($data['baselines'])->toHaveCount(1)
        ->and(DB::table('schedules')->count())->toBe(1);
    $this->b->request('POST', "{$this->url}/import", ['file' => planilhaPlanejamento($rows)])->assertUnprocessable();
});

it('importação reconhece a unidade cadastrada por código, nome ou apelido; desconhecida fica como está, com aviso', function () {
    $this->b->post('/api/v1/units', ['code' => 'UN', 'name' => 'Unidade'])->assertCreated();
    $this->b->post('/api/v1/units', ['code' => 'M3', 'name' => 'Metro cúbico'])->assertCreated();
    $rows = [
        ['A' => 'Etapa', 'B' => '1', 'C' => 'DEMO A', 'D' => '02/03/2026', 'E' => '02/03/2026', 'I' => '1', 'J' => 'und'],
        ['A' => 'Etapa', 'B' => '2', 'C' => 'DEMO B', 'D' => '02/03/2026', 'E' => '02/03/2026', 'I' => '2', 'J' => 'm³'],
        ['A' => 'Etapa', 'B' => '3', 'C' => 'DEMO C', 'D' => '02/03/2026', 'E' => '02/03/2026', 'I' => '3', 'J' => 'metro cubico'],
        ['A' => 'Etapa', 'B' => '4', 'C' => 'DEMO D', 'D' => '02/03/2026', 'E' => '02/03/2026', 'I' => '4', 'J' => 'saco'],
    ];
    $prev = $this->b->request('POST', "{$this->url}/import/preview", ['file' => planilhaPlanejamento($rows)])->assertOk()->json('data.rows');
    expect(array_column(array_column($prev, 'task'), 'unit'))->toBe(['UN', 'M3', 'M3', 'saco'])
        ->and($prev[3]['warnings'][0])->toContain('Unidade "saco" não cadastrada');
});
