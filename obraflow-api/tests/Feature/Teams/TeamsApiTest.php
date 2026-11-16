<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Modules\Audit\AuditEvent;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;

/* Fase 3 — etapa 5: API de equipes e equipamentos (permissões, isolamento, validação, D-P21, auditoria). Dados "DEMO". */

const CPF_DEMO = '529.982.247-25';

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
    $this->adminA = Scenario::member($this->A, 'admin');
    $this->b = new Browser($this);
    $this->b->login($this->adminA->email)->assertOk();
    $this->co = $this->b->post('/api/v1/companies', ['legal_name' => 'DEMO Construtora', 'document_number' => '11222333000181'])->assertCreated()->json('data.uuid');
    $this->co2 = $this->b->post('/api/v1/companies', ['legal_name' => 'DEMO Outra', 'document_number' => '11444777000161'])->assertCreated()->json('data.uuid');
});

function newEmployee(Browser $b, string $company, array $x = []): string
{
    return $b->post('/api/v1/employees', ['company_uuid' => $company, 'name' => 'DEMO Funcionário', ...$x])->assertCreated()->json('data.uuid');
}

function loginAs(object $t, $user): Browser
{
    $b = new Browser($t);
    $b->login($user->email)->assertOk();

    return $b;
}

// ---------------------------------------------------------------- cargos e setores
it('cargos e setores: CRUD, nome único no tenant, exclusão bloqueada quando em uso, auditoria', function () {
    foreach (['job-titles', 'departments'] as $path) {
        $id = $this->b->post("/api/v1/{$path}", ['name' => 'DEMO Pedreiro'])->assertCreated()->json('data.id');
        $this->b->post("/api/v1/{$path}", ['name' => 'DEMO Pedreiro'])->assertUnprocessable()->assertJsonValidationErrors('name', 'error.details');
        $this->b->put("/api/v1/{$path}/{$id}", ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->b->get("/api/v1/{$path}")->assertOk()->assertJsonPath('data.0.name', 'DEMO Pedreiro');
    }

    $cargo = $this->b->post('/api/v1/job-titles', ['name' => 'DEMO Mestre'])->json('data.id');
    newEmployee($this->b, $this->co, ['job_title_id' => $cargo]);
    $this->b->delete("/api/v1/job-titles/{$cargo}")->assertStatus(409)->assertJsonPath('error.code', 'in_use');

    $livre = $this->b->post('/api/v1/departments', ['name' => 'DEMO Livre'])->json('data.id');
    $this->b->delete("/api/v1/departments/{$livre}")->assertNoContent();

    expect(AuditLog::query()->where('event', AuditEvent::REGISTRY_CREATED)->where('entity_type', 'job_title')->count())->toBe(2)
        ->and(AuditLog::query()->where('event', AuditEvent::REGISTRY_DELETED)->where('entity_type', 'department')->count())->toBe(1);
});

// ---------------------------------------------------------------- funcionários
it('funcionário: cria, lista, mostra, edita e exclui; CPF normalizado e completo para quem administra', function () {
    $uuid = newEmployee($this->b, $this->co, ['document_number' => CPF_DEMO, 'registration_number' => 'M-1',
        'labor_type' => 'direct', 'contract_type' => 'monthly', 'hired_at' => '2026-01-10']);

    $this->b->get("/api/v1/employees/{$uuid}")->assertOk()
        ->assertJsonPath('data.document_number', '52998224725')
        ->assertJsonPath('data.company.uuid', $this->co)
        ->assertJsonMissingPath('data.id')->assertJsonMissingPath('data.tenant_id');
    $this->b->get('/api/v1/employees?q=M-1')->assertOk()->assertJsonPath('meta.total', 1);
    $this->b->get('/api/v1/employees?q=529.982')->assertOk()->assertJsonPath('meta.total', 1);
    $this->b->put("/api/v1/employees/{$uuid}", ['phone' => '(65) 99999-0000'])->assertOk()->assertJsonPath('data.phone', '(65) 99999-0000');
    $this->b->delete("/api/v1/employees/{$uuid}")->assertNoContent();
    $this->b->get("/api/v1/employees/{$uuid}")->assertNotFound();

    expect(AuditLog::query()->where('entity_type', 'employee')->pluck('event')->all())
        ->toBe([AuditEvent::REGISTRY_CREATED, AuditEvent::REGISTRY_UPDATED, AuditEvent::REGISTRY_DELETED]);
});

it('D-P21: quem só consulta funcionários recebe o CPF mascarado e não busca por CPF', function () {
    $uuid = newEmployee($this->b, $this->co, ['document_number' => CPF_DEMO]);
    app(TenantContext::class)->run($this->A, function () {
        Role::create(['name' => 'rh-leitura', 'guard_name' => 'web'])->givePermissionTo('employees.view');
    });
    $leitor = Scenario::member($this->A, 'rh-leitura');
    $lb = loginAs($this, $leitor);

    $lb->get("/api/v1/employees/{$uuid}")->assertOk()->assertJsonPath('data.document_number', '***.982.247-**');
    $lb->get('/api/v1/employees?q=52998224725')->assertOk()->assertJsonPath('meta.total', 0);
    $lb->post('/api/v1/employees', ['company_uuid' => $this->co, 'name' => 'x'])->assertForbidden();
});

it('funcionário: validações de CPF, unicidade na empresa, empresa de outro tenant e campos fechados', function () {
    newEmployee($this->b, $this->co, ['document_number' => CPF_DEMO, 'registration_number' => 'M-1']);
    $coB = app(TenantContext::class)->run($this->B, fn () => Company::create(['legal_name' => 'DEMO B', 'document_number' => '11222333000181']));

    $this->b->post('/api/v1/employees', ['company_uuid' => $this->co, 'name' => 'x', 'document_number' => '529.982.247-26'])
        ->assertUnprocessable()->assertJsonValidationErrors('document_number', 'error.details');
    $this->b->post('/api/v1/employees', ['company_uuid' => $this->co, 'name' => 'x', 'document_number' => CPF_DEMO])
        ->assertUnprocessable()->assertJsonValidationErrors('document_number', 'error.details');
    $this->b->post('/api/v1/employees', ['company_uuid' => $this->co, 'name' => 'x', 'registration_number' => 'M-1'])
        ->assertUnprocessable()->assertJsonValidationErrors('registration_number', 'error.details');
    newEmployee($this->b, $this->co2, ['document_number' => CPF_DEMO, 'registration_number' => 'M-1']); // outra empresa: ok
    $this->b->post('/api/v1/employees', ['company_uuid' => $coB->uuid, 'name' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('company_uuid', 'error.details');
    $this->b->post('/api/v1/employees', ['company_uuid' => $this->co, 'name' => 'x', 'labor_type' => 'terceiro', 'contract_type' => 'semanal'])
        ->assertUnprocessable()->assertJsonValidationErrors(['labor_type', 'contract_type'], 'error.details');
    $this->b->post('/api/v1/employees', ['company_uuid' => $this->co, 'name' => 'x', 'hired_at' => '2026-05-01', 'terminated_at' => '2026-04-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('terminated_at', 'error.details');
});

it('funcionário: responsável de outra empresa → 422 domain_rule; ele mesmo → 422; usuário só se for membro ativo e uma vez', function () {
    $chefeOutra = newEmployee($this->b, $this->co2);
    $this->b->post('/api/v1/employees', ['company_uuid' => $this->co, 'name' => 'x', 'supervisor_uuid' => $chefeOutra])
        ->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');

    $e = newEmployee($this->b, $this->co);
    $this->b->put("/api/v1/employees/{$e}", ['supervisor_uuid' => $e])->assertUnprocessable()->assertJsonValidationErrors('supervisor_uuid', 'error.details');

    $estranho = Scenario::member($this->B, 'admin');
    $this->b->post('/api/v1/employees', ['company_uuid' => $this->co, 'name' => 'x', 'user_uuid' => $estranho->uuid])
        ->assertUnprocessable()->assertJsonValidationErrors('user_uuid', 'error.details');

    $colega = Scenario::member($this->A, 'member');
    $this->b->put("/api/v1/employees/{$e}", ['user_uuid' => $colega->uuid])->assertOk()->assertJsonPath('data.user.uuid', $colega->uuid);
    $this->b->post('/api/v1/employees', ['company_uuid' => $this->co, 'name' => 'y', 'user_uuid' => $colega->uuid])
        ->assertUnprocessable()->assertJsonValidationErrors('user_uuid', 'error.details');
});

it('certificações: CRUD, vencimento após emissão, comprovante só do tenant', function () {
    $e = newEmployee($this->b, $this->co);
    $doc = $this->b->post('/api/v1/documents', ['title' => 'DEMO NR-35'])->assertCreated()->json('data.uuid');

    $id = $this->b->post("/api/v1/employees/{$e}/certifications", ['name' => 'NR-35', 'issued_at' => '2026-01-01', 'expires_at' => '2027-01-01', 'document_uuid' => $doc])
        ->assertCreated()->assertJsonPath('data.document.uuid', $doc)->assertJsonPath('data.expired', false)->json('data.id');
    $this->b->post("/api/v1/employees/{$e}/certifications", ['name' => 'x', 'issued_at' => '2026-01-01', 'expires_at' => '2025-01-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('expires_at', 'error.details');
    $this->b->post("/api/v1/employees/{$e}/certifications", ['name' => 'x', 'document_uuid' => '00000000-0000-4000-8000-000000000000'])
        ->assertUnprocessable()->assertJsonValidationErrors('document_uuid', 'error.details');
    $this->b->put("/api/v1/employees/{$e}/certifications/{$id}", ['expires_at' => '2025-06-01', 'issued_at' => '2025-01-01'])->assertOk()->assertJsonPath('data.expired', true);
    $this->b->get("/api/v1/employees/{$e}/certifications")->assertOk()->assertJsonCount(1, 'data');
    $this->b->delete("/api/v1/employees/{$e}/certifications/{$id}")->assertNoContent();
});

// ---------------------------------------------------------------- grupos e equipamentos
it('equipamentos: CRUD com grupo, unidade e fornecedor da mesma empresa; código único na empresa', function () {
    $g = $this->b->post('/api/v1/resource-groups', ['name' => 'DEMO Andaimes'])->assertCreated()->assertJsonPath('data.kind', 'equipment')->json('data.id');
    $sub = $this->b->post('/api/v1/resource-groups', ['name' => 'DEMO Tubular', 'parent_id' => $g])->assertCreated()->json('data.id');
    $this->b->post('/api/v1/resource-groups', ['name' => 'x', 'parent_id' => $sub])->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');
    $this->b->post('/api/v1/resource-groups', ['name' => 'x', 'kind' => 'material'])->assertUnprocessable();
    $unit = $this->b->post('/api/v1/units', ['code' => 'h', 'name' => 'hora'])->assertCreated()->json('data.id');
    $sup = $this->b->post('/api/v1/suppliers', ['company_uuid' => $this->co, 'type' => 'company', 'document_number' => '11222333000181', 'legal_name' => 'DEMO Locadora'])->json('data.uuid');
    $supOutra = $this->b->post('/api/v1/suppliers', ['company_uuid' => $this->co2, 'type' => 'company', 'document_number' => '11222333000181', 'legal_name' => 'DEMO Locadora 2'])->json('data.uuid');

    $uuid = $this->b->post('/api/v1/equipment', ['company_uuid' => $this->co, 'name' => 'DEMO Betoneira', 'code' => 'EQ-1', 'group_id' => $sub,
        'unit_id' => $unit, 'main_supplier_uuid' => $sup, 'ownership' => 'rented'])
        ->assertCreated()->assertJsonPath('data.group.id', $sub)->assertJsonPath('data.main_supplier.uuid', $sup)->assertJsonMissingPath('data.group_kind')->json('data.uuid');

    $this->b->post('/api/v1/equipment', ['company_uuid' => $this->co, 'name' => 'x', 'code' => 'EQ-1'])->assertUnprocessable()->assertJsonValidationErrors('code', 'error.details');
    $this->b->post('/api/v1/equipment', ['company_uuid' => $this->co, 'name' => 'x', 'main_supplier_uuid' => $supOutra])->assertUnprocessable()->assertJsonValidationErrors('main_supplier_uuid', 'error.details');
    $this->b->put("/api/v1/equipment/{$uuid}", ['status' => 'maintenance'])->assertOk()->assertJsonPath('data.status', 'maintenance');
    $this->b->get('/api/v1/equipment?status=maintenance&q=beton')->assertOk()->assertJsonPath('meta.total', 1);
    $this->b->delete("/api/v1/resource-groups/{$g}")->assertStatus(409)->assertJsonPath('error.code', 'in_use');
    $this->b->delete("/api/v1/equipment/{$uuid}")->assertNoContent();
});

// ---------------------------------------------------------------- permissões e isolamento
it('member (D-P28): consulta equipamentos, não funcionários; não escreve nada', function () {
    newEmployee($this->b, $this->co);
    $mb = loginAs($this, Scenario::member($this->A, 'member'));

    $mb->get('/api/v1/equipment')->assertOk();
    $mb->get('/api/v1/resource-groups')->assertOk();
    $mb->get('/api/v1/employees')->assertForbidden();
    $mb->get('/api/v1/job-titles')->assertForbidden();
    $mb->post('/api/v1/equipment', ['company_uuid' => $this->co, 'name' => 'x'])->assertForbidden();
    $mb->post('/api/v1/resource-groups', ['name' => 'x'])->assertForbidden();
});

it('registros de outro tenant respondem 404 (funcionário, equipamento, cargo, grupo)', function () {
    $e = newEmployee($this->b, $this->co);
    $eq = $this->b->post('/api/v1/equipment', ['company_uuid' => $this->co, 'name' => 'DEMO'])->json('data.uuid');
    $cargo = $this->b->post('/api/v1/job-titles', ['name' => 'DEMO'])->json('data.id');
    $g = $this->b->post('/api/v1/resource-groups', ['name' => 'DEMO'])->json('data.id');

    $bb = loginAs($this, Scenario::member($this->B, 'admin'));
    $bb->get("/api/v1/employees/{$e}")->assertNotFound();
    $bb->put("/api/v1/employees/{$e}", ['name' => 'x'])->assertNotFound();
    $bb->get("/api/v1/equipment/{$eq}")->assertNotFound();
    $bb->delete("/api/v1/equipment/{$eq}")->assertNotFound();
    $bb->put("/api/v1/job-titles/{$cargo}", ['name' => 'x'])->assertNotFound();
    $bb->delete("/api/v1/resource-groups/{$g}")->assertNotFound();
    $bb->get("/api/v1/employees/{$e}/certifications")->assertNotFound();
    $bb->get('/api/v1/employees')->assertOk()->assertJsonPath('meta.total', 0);
});

it('token: equipment.view lê equipamentos, mas não escreve nem lê funcionários (D-29)', function () {
    $token = $this->b->post('/api/v1/tokens', ['name' => 'erp', 'expires_in_days' => 30, 'abilities' => ['equipment.view']])->assertCreated()->json('token');

    $this->withToken($token)->getJson('/api/v1/equipment')->assertOk();
    $this->withToken($token)->postJson('/api/v1/equipment', ['company_uuid' => $this->co, 'name' => 'x'])->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/employees')->assertForbidden();
});
