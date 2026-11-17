<?php

use App\Models\AuditLog;
use App\Models\ProjectCalendarException;
use App\Modules\Audit\AuditEvent;
use App\Modules\Projects\Services\ProjectCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\Browser;
use Tests\Support\Scenario;

/* Calendário (exceções) e horários da obra — CALENDARIO_OBRA_MODELO.md (D-P57…D-P64). Dados "DEMO". */

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->admin = Scenario::member($this->A, 'admin');
    $this->member = Scenario::member($this->A, 'member');
    $this->b = new Browser($this);
    $this->b->login($this->admin->email)->assertOk();
    $this->co = $this->b->post('/api/v1/companies', ['legal_name' => 'DEMO Construtora', 'document_number' => '11222333000181'])->json('data.uuid');
    $this->obra = $this->b->post('/api/v1/projects', ['company_uuid' => $this->co, 'code' => 'DEMO-CAL', 'name' => 'DEMO Obra',
        'start_date' => '2026-01-05', 'expected_end_date' => '2026-12-31'])->assertCreated()->json('data.uuid');
    $this->url = "/api/v1/projects/{$this->obra}";
});

function semana(array $uteis, string $e1 = '07:00', string $s1 = '12:00', string $e2 = '13:00', string $s2 = '17:00'): array
{
    return array_map(fn ($d) => in_array($d, $uteis, true) ? ['start_1' => $e1, 'end_1' => $s1, 'start_2' => $e2, 'end_2' => $s2] : [], range(0, 6));
}

it('exceção única e recorrente: validação, ocorrências no período e limite do "até" e do término da obra', function () {
    $this->b->post("{$this->url}/calendar/exceptions", ['description' => 'x', 'date' => '2026-03-01', 'kind' => 'recurring'])
        ->assertUnprocessable()->assertJsonValidationErrors(['repeat_every', 'repeat_unit'], 'error.details');
    $this->b->post("{$this->url}/calendar/exceptions", ['description' => 'x', 'date' => '2026-03-10', 'kind' => 'recurring', 'repeat_every' => 1, 'repeat_unit' => 'week', 'repeat_until' => '2026-03-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('repeat_until', 'error.details');

    // única: repetição enviada é descartada (CHECK exige vazia)
    $this->b->post("{$this->url}/calendar/exceptions", ['description' => 'DEMO Parada', 'date' => '2026-03-04', 'kind' => 'single', 'repeat_every' => 3])
        ->assertCreated()->assertJsonPath('data.repeat_every', null)->assertJsonPath('data.created_by.uuid', $this->admin->uuid);
    // quinzenal (14 dias) até 31/03
    $this->b->post("{$this->url}/calendar/exceptions", ['description' => 'DEMO Manutenção', 'date' => '2026-03-02', 'kind' => 'recurring', 'repeat_every' => 1, 'repeat_unit' => 'fortnight', 'repeat_until' => '2026-03-31'])->assertCreated();
    // mensal no dia 31: cai no último dia dos meses curtos; sem "até" vale até o término da obra (31/12/2026)
    $mensal = $this->b->post("{$this->url}/calendar/exceptions", ['description' => 'DEMO Fechamento', 'date' => '2026-01-31', 'kind' => 'recurring', 'repeat_every' => 1, 'repeat_unit' => 'month'])
        ->assertCreated()->json('data.uuid');

    $dias = fn (string $from, string $to) => collect($this->b->get("{$this->url}/calendar?from={$from}&to={$to}")->assertOk()->json('data.days'))
        ->map(fn ($d) => $d['date'].' '.$d['description'])->all();

    expect($dias('2026-02-01', '2026-03-31'))->toBe([
        '2026-02-28 DEMO Fechamento', '2026-03-02 DEMO Manutenção', '2026-03-04 DEMO Parada', '2026-03-16 DEMO Manutenção',
        '2026-03-30 DEMO Manutenção', '2026-03-31 DEMO Fechamento',
    ])->and($dias('2027-01-01', '2027-02-28'))->toBe([]); // depois do término previsto

    $this->b->put("{$this->url}/calendar/exceptions/{$mensal}", ['description' => 'DEMO Fechamento', 'date' => '2026-01-31', 'kind' => 'recurring', 'repeat_every' => 2, 'repeat_unit' => 'month'])
        ->assertOk()->assertJsonPath('data.repeat_every', 2);
    expect($dias('2026-02-01', '2026-04-30'))->toContain('2026-03-31 DEMO Fechamento')->not->toContain('2026-04-30 DEMO Fechamento');

    $this->b->delete("{$this->url}/calendar/exceptions/{$mensal}")->assertNoContent();
    expect(DB::table('project_calendar_exceptions')->count())->toBe(2);
    $this->b->get("{$this->url}/calendar?from=2026-01-01&to=2027-12-31")->assertUnprocessable(); // período longo demais
});

it('ocorrências em períodos longos não dependem de percorrer desde o início (diário, anual)', function () {
    $e = new ProjectCalendarException(['description' => 'x', 'date' => '2020-02-29', 'kind' => 'recurring', 'repeat_every' => 1, 'repeat_unit' => 'year']);
    $cal = app(ProjectCalendar::class);
    $p = fn (string $d) => CarbonImmutable::parse($d);
    expect($cal->occurrences($e, $p('2023-01-01'), $p('2024-12-31')))->toBe(['2023-02-28', '2024-02-29']);

    $d = new ProjectCalendarException(['description' => 'x', 'date' => '2026-01-01', 'kind' => 'recurring', 'repeat_every' => 3, 'repeat_unit' => 'day']);
    expect($cal->occurrences($d, $p('2026-01-05'), $p('2026-01-12')))->toBe(['2026-01-07', '2026-01-10']);
});

it('importa sábados e domingos (2 exceções semanais) e feriados nacionais do ano, sem duplicar', function () {
    $this->b->post("{$this->url}/calendar/import-weekends")->assertOk()->assertJsonPath('data.created', 2);
    $this->b->post("{$this->url}/calendar/import-weekends")->assertOk()->assertJsonPath('data.created', 0);

    $semana = collect($this->b->get("{$this->url}/calendar?from=2026-01-05&to=2026-01-18")->json('data.days'))->pluck('date')->all();
    expect($semana)->toBe(['2026-01-10', '2026-01-11', '2026-01-17', '2026-01-18']); // obra começa na segunda 05/01

    // Páscoa 2026 = 05/04 → Carnaval 16 e 17/02, Sexta-feira Santa 03/04, Corpus Christi 04/06; Consciência Negra (Lei 14.759/2023)
    $this->b->post("{$this->url}/calendar/import-holidays", ['year' => 2026])->assertOk()->assertJsonPath('data.created', 13);
    $this->b->post("{$this->url}/calendar/import-holidays", ['year' => 2026])->assertOk()->assertJsonPath('data.created', 0);
    $nomes = DB::table('project_calendar_exceptions')->where('kind', 'single')->pluck('description', 'date');
    expect($nomes['2026-02-17'])->toBe('Carnaval (ponto facultativo)')->and($nomes['2026-04-03'])->toBe('Sexta-feira Santa')
        ->and($nomes['2026-06-04'])->toBe('Corpus Christi (ponto facultativo)')->and($nomes['2026-11-20'])->toContain('Consciência Negra')
        ->and(ProjectCalendar::easter(2025)->toDateString())->toBe('2025-04-20')->and(ProjectCalendar::easter(2024)->toDateString())->toBe('2024-03-31');
    $this->b->post("{$this->url}/calendar/import-holidays", ['year' => 1900])->assertUnprocessable();
});

it('horário: padrão da empresa herdado pela obra; obra ajusta e volta ao padrão; dias sem horário são de folga', function () {
    $this->b->get("{$this->url}/work-schedule")->assertOk()->assertJsonPath('data.source', 'none')->assertJsonPath('data.weekly_hours', '0:00');

    $this->b->put("/api/v1/companies/{$this->co}/work-schedule", ['days' => semana([1, 2, 3, 4, 5])])->assertOk()
        ->assertJsonPath('data.weekly_hours', '45:00')->assertJsonPath('data.days.1.hours', '9:00');
    $this->b->get("{$this->url}/work-schedule")->assertOk()->assertJsonPath('data.source', 'company')->assertJsonPath('data.days.1.start_1', '07:00');
    $this->b->get("{$this->url}/calendar?from=2026-01-05&to=2026-01-11")->assertJsonPath('data.non_working_weekdays', [0, 6]);

    // obra trabalha sábado de manhã e sai às 16h
    $propria = semana([1, 2, 3, 4, 5], s2: '16:00');
    $propria[6] = ['start_1' => '07:00', 'end_1' => '11:00'];
    $this->b->put("{$this->url}/work-schedule", ['days' => $propria])->assertOk()->assertJsonPath('data.source', 'project')
        ->assertJsonPath('data.weekly_hours', '44:00')->assertJsonPath('data.days.6.hours', '4:00');
    $this->b->get("{$this->url}/calendar?from=2026-01-05&to=2026-01-11")->assertJsonPath('data.non_working_weekdays', [0]);

    $this->b->delete("{$this->url}/work-schedule")->assertOk()->assertJsonPath('data.source', 'company');
    expect(DB::table('work_schedules')->whereNotNull('project_id')->count())->toBe(0);

    // validação: saída antes da entrada; 2º período antes do 1º; período incompleto
    $ruim = semana([1]);
    $ruim[1]['end_1'] = '06:00';
    $this->b->put("{$this->url}/work-schedule", ['days' => $ruim])->assertUnprocessable()->assertJsonValidationErrors('days.1.end_1', 'error.details');
    $ruim = semana([1], e2: '11:00');
    $this->b->put("{$this->url}/work-schedule", ['days' => $ruim])->assertUnprocessable()->assertJsonValidationErrors('days.1.start_2', 'error.details');
    $this->b->put("{$this->url}/work-schedule", ['days' => [[], ['start_1' => '07:00'], [], [], [], [], []]])->assertUnprocessable();
    $this->b->put("{$this->url}/work-schedule", ['days' => [[]]])->assertUnprocessable(); // semana incompleta
});

it('banco recusa horário incoerente e exceção sem regra (CHECKs)', function () {
    $projectId = DB::table('projects')->where('uuid', $this->obra)->value('id');
    $base = ['tenant_id' => $this->A->id, 'created_at' => now(), 'updated_at' => now()];
    expect(fn () => DB::table('work_schedules')->insert($base + ['project_id' => $projectId, 'weekday' => 1, 'start_1' => '12:00', 'end_1' => '07:00']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('work_schedules')->insert($base + ['project_id' => $projectId, 'company_id' => DB::table('companies')->value('id'), 'weekday' => 1]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('work_schedules')->insert($base + ['project_id' => $projectId, 'weekday' => 7]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('project_calendar_exceptions')->insert($base + ['uuid' => (string) Str::uuid(), 'project_id' => $projectId, 'description' => 'x',
            'date' => '2026-01-01', 'kind' => 'recurring', 'created_by' => $this->admin->id]))->toThrow(QueryException::class);
});

it('acesso: sem acesso à obra → 404; nível view lê mas não altera; empresa exige companies.manage; outro tenant não vê', function () {
    $mb = new Browser($this);
    $mb->login($this->member->email)->assertOk();
    $mb->get("{$this->url}/calendar/exceptions")->assertNotFound();

    $this->b->post("{$this->url}/access", ['user_uuid' => $this->member->uuid, 'access_level' => 'view'])->assertOk();
    $mb->get("{$this->url}/calendar/exceptions")->assertOk();
    $mb->get("{$this->url}/work-schedule")->assertOk();
    $mb->post("{$this->url}/calendar/exceptions", ['description' => 'x', 'date' => '2026-01-01', 'kind' => 'single'])->assertForbidden();
    $mb->post("{$this->url}/calendar/import-weekends")->assertForbidden();
    $mb->put("{$this->url}/work-schedule", ['days' => semana([1])])->assertForbidden();
    $mb->put("/api/v1/companies/{$this->co}/work-schedule", ['days' => semana([1])])->assertForbidden();

    $this->b->post("{$this->url}/access", ['user_uuid' => $this->member->uuid, 'access_level' => 'edit'])->assertOk();
    $mb->post("{$this->url}/calendar/exceptions", ['description' => 'DEMO Chuva', 'date' => '2026-01-07', 'kind' => 'single'])->assertCreated();

    $B = Scenario::tenant();
    $outro = Scenario::member($B, 'admin');
    $ob = new Browser($this);
    $ob->login($outro->email)->assertOk();
    $ob->get("{$this->url}/calendar/exceptions")->assertNotFound();
    $ob->get("/api/v1/companies/{$this->co}/work-schedule")->assertNotFound();
});

it('todo evento do calendário é emitido e auditado no tenant', function () {
    $this->b->post("{$this->url}/calendar/import-weekends")->assertOk();
    $this->b->put("{$this->url}/work-schedule", ['days' => semana([1, 2, 3, 4, 5])])->assertOk();
    $this->b->delete("{$this->url}/work-schedule")->assertOk();
    $this->b->post("{$this->url}/calendar/exceptions", ['description' => 'DEMO', 'date' => '2026-01-07', 'kind' => 'single'])->assertCreated();

    $emitidos = AuditLog::query()->distinct()->pluck('event')->all();
    expect(array_values(array_diff(AuditEvent::calendar(), $emitidos)))->toBe([])
        ->and(AuditLog::query()->whereIn('event', AuditEvent::calendar())->pluck('tenant_id')->unique()->all())->toBe([$this->A->id])
        ->and(AuditLog::query()->where('event', AuditEvent::REGISTRY_CREATED)->where('entity_type', 'project_calendar_exception')->exists())->toBeTrue();
});
