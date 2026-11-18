<?php

use App\Models\AuditLog;
use App\Models\OwnComposition;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;

/* Fase 6B — composições próprias simplificadas (D-P87/D-P88). Dados "DEMO". */

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->admin = Scenario::member($this->A, 'admin');
    $this->b = new Browser($this);
    $this->b->login($this->admin->email)->assertOk();
    $this->m2 = $this->b->post('/api/v1/units', ['code' => 'M2', 'name' => 'metro quadrado'])->assertCreated()->json('data.id');
    $this->body = [
        'code' => 'CP-001', 'name' => 'DEMO Contrapiso 5 cm', 'unit_id' => $this->m2, 'reference_date' => '2026-11-01', 'state' => 'MT',
        'material_value' => '18.40', 'service_value' => '2.00', 'other_value' => '0.35',
        'equipment_value_none' => '1.10', 'equipment_value_relief' => '1.20', 'equipment_value_no_relief' => '1.30',
        'labor_value_none' => '9.00', 'labor_value_relief' => '14.50', 'labor_value_no_relief' => '16.75',
    ];
});

it('cadastra com um valor por tipo e devolve o total de cada regime (D-P88)', function () {
    $c = $this->b->post('/api/v1/own-compositions', $this->body)->assertCreated()->json('data');

    expect($c)->toMatchArray(['code' => 'CP-001', 'state' => 'MT', 'reference_date' => '2026-11-01', 'labor_value_relief' => '14.50', 'status' => 'active'])
        ->and($c['unit']['code'])->toBe('M2')
        // 18,40 + 2,00 + 0,35 + equipamento + mão de obra do regime
        ->and($c['totals'])->toBe(['with_relief' => '36.45', 'without_relief' => '38.80', 'not_applicable' => '30.85']);

    $model = app(TenantContext::class)->run($this->A, fn () => OwnComposition::query()->sole(), $this->admin);
    expect($model->costsFor('with_relief'))->toBe(['material' => '18.40', 'labor' => '14.50', 'equipment' => '1.20', 'service' => '2.00', 'other' => '0.35'])
        ->and(AuditLog::query()->where('event', 'registry.created')->where('entity_type', 'own_composition')->count())->toBe(1);
});

it('valida: obrigatórios, valores ≥ 0 com 2 casas, UF, unidade do tenant e código único entre as ativas', function () {
    $this->b->post('/api/v1/own-compositions', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'unit_id', 'reference_date'], 'error.details');
    $this->b->post('/api/v1/own-compositions', ['material_value' => '-1'] + $this->body)->assertUnprocessable()->assertJsonValidationErrors('material_value', 'error.details');
    $this->b->post('/api/v1/own-compositions', ['labor_value_relief' => '1.234'] + $this->body)->assertUnprocessable()->assertJsonValidationErrors('labor_value_relief', 'error.details');
    $this->b->post('/api/v1/own-compositions', ['state' => 'mt'] + $this->body)->assertUnprocessable()->assertJsonValidationErrors('state', 'error.details');

    $uuid = $this->b->post('/api/v1/own-compositions', $this->body)->assertCreated()->json('data.uuid');
    $this->b->post('/api/v1/own-compositions', $this->body)->assertUnprocessable()->assertJsonValidationErrors('code', 'error.details');
    $this->b->put("/api/v1/own-compositions/{$uuid}", ['code' => 'CP-001', 'name' => 'DEMO Contrapiso 6 cm'])->assertOk()->assertJsonPath('data.name', 'DEMO Contrapiso 6 cm');

    // excluída (lógica): o código volta a ficar livre
    $this->b->delete("/api/v1/own-compositions/{$uuid}")->assertNoContent();
    $this->b->get("/api/v1/own-compositions/{$uuid}")->assertNotFound();
    $this->b->post('/api/v1/own-compositions', $this->body)->assertCreated();
});

it('lista com busca e filtro; permissões (member consulta, não cria); outro tenant não vê', function () {
    $this->b->post('/api/v1/own-compositions', $this->body)->assertCreated();
    $this->b->post('/api/v1/own-compositions', ['code' => 'CP-002', 'name' => 'DEMO Reboco', 'status' => 'inactive'] + $this->body)->assertCreated();

    expect($this->b->get('/api/v1/own-compositions?q=reboco')->assertOk()->json('meta.total'))->toBe(1)
        ->and($this->b->get('/api/v1/own-compositions?status=active')->json('data.0.code'))->toBe('CP-001');

    $m = new Browser($this);
    $m->login(Scenario::member($this->A, 'member')->email)->assertOk();
    $m->get('/api/v1/own-compositions')->assertOk();
    $m->post('/api/v1/own-compositions', ['code' => 'CP-9'] + $this->body)->assertForbidden();

    $uuid = $this->b->get('/api/v1/own-compositions?q=reboco')->json('data.0.uuid');
    $o = new Browser($this);
    $o->login(Scenario::member(Scenario::tenant(), 'admin')->email)->assertOk();
    $o->get('/api/v1/own-compositions')->assertOk()->assertJsonPath('meta.total', 0);
    $o->get("/api/v1/own-compositions/{$uuid}")->assertNotFound();
    $o->put("/api/v1/own-compositions/{$uuid}", ['name' => 'x'])->assertNotFound();
});
