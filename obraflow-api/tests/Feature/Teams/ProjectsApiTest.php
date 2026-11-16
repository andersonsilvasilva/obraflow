<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ProjectStatusHistory;
use App\Modules\Audit\AuditEvent;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;

/*
 * Fase 3 — etapa 5: API de obras. Acesso por obra (D-P24), status com histórico (D-P27), equipe, fases,
 * endereço, vínculo de documentos (D-P19), isolamento e auditoria. Dados "DEMO".
 */

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
    $this->adminA = Scenario::member($this->A, 'admin');
    $this->memberA = Scenario::member($this->A, 'member');
    $this->b = new Browser($this);
    $this->b->login($this->adminA->email)->assertOk();
    $this->mb = new Browser($this);
    $this->mb->login($this->memberA->email)->assertOk();

    $this->co = $this->b->post('/api/v1/companies', ['legal_name' => 'DEMO Construtora', 'document_number' => '11222333000181'])->json('data.uuid');
    $this->co2 = $this->b->post('/api/v1/companies', ['legal_name' => 'DEMO Outra', 'document_number' => '11444777000161'])->json('data.uuid');
    $this->obra = $this->b->post('/api/v1/projects', ['company_uuid' => $this->co, 'code' => 'DEMO-OBRA-1', 'name' => 'DEMO Residencial'])
        ->assertCreated()->json('data.uuid');
});

function grantTo(object $t, $user, string $level, ?string $obra = null): void
{
    $t->b->post('/api/v1/projects/'.($obra ?? $t->obra).'/access', ['user_uuid' => $user->uuid, 'access_level' => $level])->assertOk();
}

// ---------------------------------------------------------------- CRUD e validações
it('admin cria, lista, mostra, edita e exclui obra; status nasce planning e não é editável pelo PUT', function () {
    $cli = $this->b->post('/api/v1/customers', ['company_uuid' => $this->co, 'type' => 'person', 'name' => 'DEMO Cliente'])->json('data.uuid');
    $resp = $this->b->post('/api/v1/employees', ['company_uuid' => $this->co, 'name' => 'DEMO Engenheira'])->json('data.uuid');

    $this->b->put("/api/v1/projects/{$this->obra}", ['customer_uuid' => $cli, 'technical_manager_uuid' => $resp, 'contract_value' => '150000.50',
        'start_date' => '2026-02-01', 'expected_end_date' => '2026-12-20', 'progress' => 10])
        ->assertOk()->assertJsonPath('data.customer.uuid', $cli)->assertJsonPath('data.technical_manager.uuid', $resp)
        ->assertJsonPath('data.contract_value', '150000.50')->assertJsonPath('data.status', 'planning')->assertJsonPath('data.access_level', 'manage');
    $this->b->put("/api/v1/projects/{$this->obra}", ['status' => 'completed'])->assertUnprocessable()->assertJsonValidationErrors('status', 'error.details');

    $this->b->get('/api/v1/projects?q=DEMO-OBRA')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.access_level', 'manage');
    $this->b->get("/api/v1/projects/{$this->obra}")->assertOk()->assertJsonPath('data.code', 'DEMO-OBRA-1')
        ->assertJsonMissingPath('data.id')->assertJsonMissingPath('data.tenant_id');
    $this->b->post('/api/v1/projects', ['company_uuid' => $this->co, 'code' => 'DEMO-OBRA-1', 'name' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('code', 'error.details');
    $this->b->delete("/api/v1/projects/{$this->obra}")->assertNoContent();
    $this->b->get("/api/v1/projects/{$this->obra}")->assertNotFound();
});

it('cliente e responsável técnico precisam ser da mesma empresa da obra; datas coerentes', function () {
    $cliOutra = $this->b->post('/api/v1/customers', ['company_uuid' => $this->co2, 'type' => 'person', 'name' => 'DEMO'])->json('data.uuid');
    $engOutra = $this->b->post('/api/v1/employees', ['company_uuid' => $this->co2, 'name' => 'DEMO'])->json('data.uuid');

    $this->b->put("/api/v1/projects/{$this->obra}", ['customer_uuid' => $cliOutra])->assertUnprocessable()->assertJsonValidationErrors('customer_uuid', 'error.details');
    $this->b->put("/api/v1/projects/{$this->obra}", ['technical_manager_uuid' => $engOutra])->assertUnprocessable()->assertJsonValidationErrors('technical_manager_uuid', 'error.details');
    $this->b->put("/api/v1/projects/{$this->obra}", ['start_date' => '2026-05-01', 'expected_end_date' => '2026-04-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('expected_end_date', 'error.details');
    $this->b->put("/api/v1/projects/{$this->obra}", ['progress' => 101])->assertUnprocessable();
    // edição parcial: mover só o início para depois do fim já gravado → 422 (CHECK do banco convertido), nunca 500
    $this->b->put("/api/v1/projects/{$this->obra}", ['start_date' => '2026-01-01', 'expected_end_date' => '2026-03-01'])->assertOk();
    $this->b->put("/api/v1/projects/{$this->obra}", ['expected_end_date' => '2025-12-01'])->assertUnprocessable()->assertJsonValidationErrors('expected_end_date', 'error.details');
    $this->b->put("/api/v1/projects/{$this->obra}", ['start_date' => '2026-06-01'])->assertUnprocessable()->assertJsonPath('error.code', 'constraint_violation');
});

// ---------------------------------------------------------------- acesso por obra (D-P24)
it('member só vê obras liberadas; o nível limita a ação; sem acesso = 404', function () {
    $outra = $this->b->post('/api/v1/projects', ['company_uuid' => $this->co, 'code' => 'DEMO-OBRA-2', 'name' => 'DEMO 2'])->json('data.uuid');

    $this->mb->get('/api/v1/projects')->assertOk()->assertJsonPath('meta.total', 0);
    $this->mb->get("/api/v1/projects/{$this->obra}")->assertNotFound();
    $this->mb->post('/api/v1/projects', ['company_uuid' => $this->co, 'code' => 'X', 'name' => 'x'])->assertForbidden();

    grantTo($this, $this->memberA, 'view');
    $this->mb->get('/api/v1/projects')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.access_level', 'view');
    $this->mb->get("/api/v1/projects/{$this->obra}")->assertOk()->assertJsonPath('data.access_level', 'view');
    $this->mb->put("/api/v1/projects/{$this->obra}", ['name' => 'x'])->assertForbidden();
    $this->mb->get("/api/v1/projects/{$outra}")->assertNotFound();

    grantTo($this, $this->memberA, 'edit');
    $this->mb->put("/api/v1/projects/{$this->obra}", ['name' => 'DEMO Renomeada'])->assertOk();
    $this->mb->post("/api/v1/projects/{$this->obra}/phases", ['name' => 'DEMO Fundação'])->assertCreated();
    $this->mb->get("/api/v1/projects/{$this->obra}/access")->assertForbidden();
    $this->mb->delete("/api/v1/projects/{$this->obra}")->assertForbidden(); // excluir exige projects.manage

    grantTo($this, $this->memberA, 'manage');
    $this->mb->get("/api/v1/projects/{$this->obra}/access")->assertOk()->assertJsonPath('data.0.access_level', 'manage');
});

it('acesso: só membro ativo do tenant; concessão, alteração e revogação auditadas', function () {
    $estranho = Scenario::member($this->B, 'admin');
    $this->b->post("/api/v1/projects/{$this->obra}/access", ['user_uuid' => $estranho->uuid, 'access_level' => 'view'])
        ->assertUnprocessable()->assertJsonValidationErrors('user_uuid', 'error.details');
    $this->b->post("/api/v1/projects/{$this->obra}/access", ['user_uuid' => $this->memberA->uuid, 'access_level' => 'owner'])->assertUnprocessable();

    grantTo($this, $this->memberA, 'view');
    grantTo($this, $this->memberA, 'view'); // idempotente: sem novo evento
    grantTo($this, $this->memberA, 'edit');
    $this->b->delete("/api/v1/projects/{$this->obra}/access/{$this->memberA->uuid}")->assertNoContent();
    $this->b->delete("/api/v1/projects/{$this->obra}/access/{$this->memberA->uuid}")->assertNotFound();
    $this->mb->get("/api/v1/projects/{$this->obra}")->assertNotFound();

    $logs = AuditLog::query()->whereIn('event', [AuditEvent::PROJECT_ACCESS_GRANTED, AuditEvent::PROJECT_ACCESS_REVOKED])->orderBy('id')->get();
    expect($logs->pluck('event')->all())->toBe([AuditEvent::PROJECT_ACCESS_GRANTED, AuditEvent::PROJECT_ACCESS_GRANTED, AuditEvent::PROJECT_ACCESS_REVOKED])
        ->and($logs[1]->old_values)->toBe(['user_id' => $this->memberA->id, 'access_level' => 'view'])
        ->and($logs[1]->new_values)->toBe(['user_id' => $this->memberA->id, 'access_level' => 'edit'])
        ->and($logs->pluck('tenant_id')->unique()->all())->toBe([$this->A->id]);
});

// ---------------------------------------------------------------- status (D-P27)
it('status: só transições permitidas; histórico append-only e auditoria com motivo', function () {
    $this->b->post("/api/v1/projects/{$this->obra}/status", ['status' => 'completed'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'invalid_transition');
    $this->b->post("/api/v1/projects/{$this->obra}/status", ['status' => 'in_progress', 'reason' => 'DEMO início'])
        ->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->b->post("/api/v1/projects/{$this->obra}/status", ['status' => 'paused'])->assertOk();

    $this->b->get("/api/v1/projects/{$this->obra}/status-history")->assertOk()
        ->assertJsonPath('data.0.to_status', 'paused')->assertJsonPath('data.1.from_status', 'planning')
        ->assertJsonPath('data.1.reason', 'DEMO início')->assertJsonPath('data.1.changed_by.uuid', $this->adminA->uuid);

    $log = AuditLog::query()->where('event', AuditEvent::PROJECT_STATUS_CHANGED)->orderBy('id')->first();
    expect($log->old_values)->toBe(['status' => 'planning'])->and($log->new_values)->toBe(['status' => 'in_progress'])
        ->and($log->metadata['reason'])->toBe('DEMO início')
        ->and(app(TenantContext::class)->run($this->A, fn () => ProjectStatusHistory::query()->count()))->toBe(2);

    grantTo($this, $this->memberA, 'view');
    $this->mb->post("/api/v1/projects/{$this->obra}/status", ['status' => 'in_progress'])->assertForbidden();
});

// ---------------------------------------------------------------- fases, equipe e endereço
it('fases: até 3 níveis, pai da mesma obra, exclusão de fase com filhas bloqueada', function () {
    $outra = $this->b->post('/api/v1/projects', ['company_uuid' => $this->co, 'code' => 'DEMO-OBRA-2', 'name' => 'DEMO 2'])->json('data.uuid');
    $n1 = $this->b->post("/api/v1/projects/{$this->obra}/phases", ['name' => 'DEMO 1'])->assertCreated()->json('data.id');
    $n2 = $this->b->post("/api/v1/projects/{$this->obra}/phases", ['name' => 'DEMO 1.1', 'parent_id' => $n1])->json('data.id');
    $n3 = $this->b->post("/api/v1/projects/{$this->obra}/phases", ['name' => 'DEMO 1.1.1', 'parent_id' => $n2])->json('data.id');

    $this->b->post("/api/v1/projects/{$this->obra}/phases", ['name' => 'x', 'parent_id' => $n3])->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');
    $this->b->post("/api/v1/projects/{$outra}/phases", ['name' => 'x', 'parent_id' => $n1])->assertUnprocessable()->assertJsonValidationErrors('parent_id', 'error.details');
    $this->b->put("/api/v1/projects/{$outra}/phases/{$n1}", ['name' => 'x'])->assertNotFound();
    $this->b->delete("/api/v1/projects/{$this->obra}/phases/{$n1}")->assertStatus(409)->assertJsonPath('error.code', 'in_use');
    $this->b->get("/api/v1/projects/{$this->obra}")->assertOk()->assertJsonCount(3, 'data.phases');
});

it('equipe: funcionário da mesma empresa, um vínculo em aberto; funcionário na equipe não pode ser excluído', function () {
    $func = $this->b->post('/api/v1/employees', ['company_uuid' => $this->co, 'name' => 'DEMO Mestre'])->json('data.uuid');
    $deFora = $this->b->post('/api/v1/employees', ['company_uuid' => $this->co2, 'name' => 'DEMO Fora'])->json('data.uuid');

    $id = $this->b->post("/api/v1/projects/{$this->obra}/members", ['employee_uuid' => $func, 'role' => 'Mestre de obras', 'started_on' => '2026-02-01'])
        ->assertCreated()->assertJsonPath('data.employee.uuid', $func)->json('data.id');
    $this->b->post("/api/v1/projects/{$this->obra}/members", ['employee_uuid' => $func, 'started_on' => '2026-03-01'])->assertStatus(409)->assertJsonPath('error.code', 'duplicate');
    $this->b->post("/api/v1/projects/{$this->obra}/members", ['employee_uuid' => $deFora, 'started_on' => '2026-03-01'])->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');
    $this->b->delete("/api/v1/employees/{$func}")->assertStatus(409)->assertJsonPath('error.code', 'employee_in_use');

    $this->b->put("/api/v1/projects/{$this->obra}/members/{$id}", ['ended_on' => '2026-01-01'])->assertUnprocessable();
    $this->b->put("/api/v1/projects/{$this->obra}/members/{$id}", ['ended_on' => '2026-04-30'])->assertOk()->assertJsonPath('data.ended_on', '2026-04-30');
    $this->b->post("/api/v1/projects/{$this->obra}/members", ['employee_uuid' => $func, 'started_on' => '2026-05-01'])->assertCreated();
    $this->b->get("/api/v1/projects/{$this->obra}")->assertOk()->assertJsonCount(2, 'data.members')
        ->assertJsonMissingPath('data.members.0.employee.document_number');
});

it('endereço da obra: tipo padrão "site", um único principal', function () {
    $this->b->post("/api/v1/projects/{$this->obra}/addresses", ['street' => 'Rua DEMO', 'city' => 'Cuiabá', 'state' => 'MT'])
        ->assertCreated()->assertJsonPath('data.kind', 'site');
    $this->b->post("/api/v1/projects/{$this->obra}/addresses", ['kind' => 'main', 'street' => 'Rua DEMO', 'city' => 'Cuiabá', 'state' => 'MT'])->assertCreated();
    $this->b->post("/api/v1/projects/{$this->obra}/addresses", ['kind' => 'main', 'street' => 'Rua 2', 'city' => 'Cuiabá', 'state' => 'MT'])
        ->assertStatus(409)->assertJsonPath('error.code', 'main_address_exists');
    $this->b->get("/api/v1/projects/{$this->obra}/addresses")->assertOk()->assertJsonCount(2, 'data');
});

// ---------------------------------------------------------------- documentos (D-P19)
it('documento vinculado a obra, funcionário e equipamento; vincular à obra exige nível edit; filtro respeita o acesso', function () {
    $func = $this->b->post('/api/v1/employees', ['company_uuid' => $this->co, 'name' => 'DEMO'])->json('data.uuid');
    $eq = $this->b->post('/api/v1/equipment', ['company_uuid' => $this->co, 'name' => 'DEMO'])->json('data.uuid');
    $doc = $this->b->post('/api/v1/documents', ['title' => 'DEMO Alvará'])->json('data.uuid');

    foreach (['project' => $this->obra, 'employee' => $func, 'equipment' => $eq] as $type => $key) {
        $this->b->post("/api/v1/documents/{$doc}/links", ['linkable_type' => $type, 'linkable_key' => $key])->assertCreated()->assertJsonPath('data.linkable_type', $type);
    }
    $this->b->get("/api/v1/documents?linkable_type=project&linkable_key={$this->obra}")->assertOk()->assertJsonPath('meta.total', 1);

    // member com documents.view mas sem acesso à obra: o filtro não revela nada; vincular → 404
    $this->mb->get("/api/v1/documents?linkable_type=project&linkable_key={$this->obra}")->assertOk()->assertJsonPath('meta.total', 0);
    grantTo($this, $this->memberA, 'view');
    $this->mb->get("/api/v1/documents?linkable_type=project&linkable_key={$this->obra}")->assertOk()->assertJsonPath('meta.total', 1);
    // member não tem employees.view: filtro por funcionário não revela
    $this->mb->get("/api/v1/documents?linkable_type=employee&linkable_key={$func}")->assertOk()->assertJsonPath('meta.total', 0);
});

// ---------------------------------------------------------------- isolamento, token e auditoria
it('obra de outro tenant responde 404 em todas as rotas de detalhe, mesmo para admin', function () {
    $bb = new Browser($this);
    $bb->login(Scenario::member($this->B, 'admin')->email)->assertOk();

    $bb->get("/api/v1/projects/{$this->obra}")->assertNotFound();
    $bb->put("/api/v1/projects/{$this->obra}", ['name' => 'x'])->assertNotFound();
    $bb->post("/api/v1/projects/{$this->obra}/status", ['status' => 'in_progress'])->assertNotFound();
    $bb->post("/api/v1/projects/{$this->obra}/phases", ['name' => 'x'])->assertNotFound();
    $bb->get("/api/v1/projects/{$this->obra}/access")->assertNotFound();
    $bb->get('/api/v1/projects')->assertOk()->assertJsonPath('meta.total', 0);
    $coB = app(TenantContext::class)->run($this->B, fn () => Company::create(['legal_name' => 'DEMO B', 'document_number' => '11222333000181']));
    $this->b->post('/api/v1/projects', ['company_uuid' => $coB->uuid, 'code' => 'X', 'name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('company_uuid', 'error.details');
});

it('token: projects.view não cria; sem manage no token, vale o acesso por obra (D-29 × D-P24)', function () {
    $token = $this->b->post('/api/v1/tokens', ['name' => 'bi', 'expires_in_days' => 30, 'abilities' => ['projects.view']])->assertCreated()->json('token');

    // sem projects.manage no token, até o admin passa a depender do acesso por obra (interseção D-29 × D-P24)
    $this->withToken($token)->getJson('/api/v1/projects')->assertOk()->assertJsonPath('meta.total', 0);
    $this->withToken($token)->getJson("/api/v1/projects/{$this->obra}")->assertNotFound();
    $this->withToken($token)->postJson('/api/v1/projects', ['company_uuid' => $this->co, 'code' => 'X', 'name' => 'x'])->assertForbidden();

    grantTo($this, $this->adminA, 'edit');
    $this->withToken($token)->getJson("/api/v1/projects/{$this->obra}")->assertOk()->assertJsonPath('data.access_level', 'edit');
    $this->withToken($token)->postJson("/api/v1/projects/{$this->obra}/status", ['status' => 'in_progress'])->assertOk();
});

it('todos os eventos próprios da Fase 3 são emitidos por operações reais e ficam no tenant', function () {
    grantTo($this, $this->memberA, 'view');
    $this->b->delete("/api/v1/projects/{$this->obra}/access/{$this->memberA->uuid}")->assertNoContent();
    $this->b->post("/api/v1/projects/{$this->obra}/status", ['status' => 'in_progress'])->assertOk();

    $emitidos = AuditLog::query()->distinct()->pluck('event')->all();
    expect(array_values(array_diff(AuditEvent::phase3(), $emitidos)))->toBe([])
        ->and(AuditLog::query()->whereIn('event', AuditEvent::phase3())->pluck('tenant_id')->unique()->all())->toBe([$this->A->id]);
});
