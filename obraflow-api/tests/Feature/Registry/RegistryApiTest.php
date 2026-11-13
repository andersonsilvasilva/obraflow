<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Modules\Audit\AuditEvent;
use App\Modules\Registry\Rules\TaxDocument;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;

/* Fase 2 — etapa 4a: API de cadastros (permissões, isolamento, validação, auditoria). Dados fictícios "DEMO". */

const CNPJ_A = '11.222.333/0001-81';
const CPF_OK = '529.982.247-25';

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
    $this->adminA = Scenario::member($this->A, 'admin');
    $this->adminB = Scenario::member($this->B, 'admin');
    $this->browser = new Browser($this);
    $this->browser->login($this->adminA->email)->assertOk();
});

function regCompany(Browser $b, string $cnpj = CNPJ_A, array $extra = []): string
{
    return $b->post('/api/v1/companies', ['legal_name' => 'DEMO Construtora', 'document_number' => $cnpj, ...$extra])
        ->assertCreated()->json('data.uuid');
}

/** Empresa criada direto no tenant B (sem passar pela sessão do admin A). */
function regCompanyInB($tenant): Company
{
    return app(TenantContext::class)->run($tenant, fn () => Company::create(['legal_name' => 'DEMO B', 'document_number' => '11222333000181']));
}

// ---------------------------------------------------------------- regra de CPF/CNPJ
it('valida dígitos verificadores de CPF e CNPJ (com ou sem máscara) e recusa sequências repetidas', function () {
    expect(TaxDocument::isCnpj('11222333000181'))->toBeTrue()
        ->and(TaxDocument::isCnpj('61693043000194'))->toBeTrue()
        ->and(TaxDocument::isCnpj('11222333000182'))->toBeFalse()
        ->and(TaxDocument::isCnpj('11111111111111'))->toBeFalse()
        ->and(TaxDocument::isCpf('52998224725'))->toBeTrue()
        ->and(TaxDocument::isCpf('52998224726'))->toBeFalse()
        ->and(TaxDocument::isCpf('00000000000'))->toBeFalse();
});

// ---------------------------------------------------------------- empresas: CRUD, validação, auditoria
it('cria, lista, mostra, edita e exclui (soft) empresa, auditando cada passo no tenant', function () {
    $uuid = regCompany($this->browser);

    $this->browser->get('/api/v1/companies')->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.document_number', '11222333000181');
    $this->browser->get('/api/v1/companies?q=11.222')->assertOk()->assertJsonPath('meta.total', 1);
    $this->browser->get("/api/v1/companies/{$uuid}")->assertOk()->assertJsonPath('data.legal_name', 'DEMO Construtora')
        ->assertJsonMissingPath('data.tenant_id')->assertJsonMissingPath('data.id');
    $this->browser->put("/api/v1/companies/{$uuid}", ['trade_name' => 'DEMO'])->assertOk()->assertJsonPath('data.trade_name', 'DEMO');
    $this->browser->delete("/api/v1/companies/{$uuid}")->assertNoContent();
    $this->browser->get("/api/v1/companies/{$uuid}")->assertNotFound();

    $eventos = AuditLog::query()->where('entity_type', 'company')->orderBy('id')->pluck('event')->all();
    expect($eventos)->toBe([AuditEvent::REGISTRY_CREATED, AuditEvent::REGISTRY_UPDATED, AuditEvent::REGISTRY_DELETED])
        ->and(AuditLog::query()->where('entity_type', 'company')->pluck('tenant_id')->unique()->all())->toBe([$this->A->id]);

    $upd = AuditLog::query()->where('event', AuditEvent::REGISTRY_UPDATED)->firstOrFail();
    expect($upd->old_values)->toBe(['trade_name' => null])->and($upd->new_values)->toBe(['trade_name' => 'DEMO']);
});

it('recusa CNPJ inválido e CNPJ já ativo no tenant; aceita o mesmo CNPJ em outro tenant e após exclusão', function () {
    $this->browser->post('/api/v1/companies', ['legal_name' => 'X', 'document_number' => '11.222.333/0001-82'])
        ->assertUnprocessable()->assertJsonValidationErrors('document_number', 'error.details');

    $uuid = regCompany($this->browser);
    $this->browser->post('/api/v1/companies', ['legal_name' => 'Dup', 'document_number' => '11222333000181'])
        ->assertUnprocessable()->assertJsonValidationErrors('document_number', 'error.details');

    regCompanyInB($this->B); // mesmo CNPJ em B: permitido

    $this->browser->delete("/api/v1/companies/{$uuid}")->assertNoContent();
    regCompany($this->browser); // recadastro após soft delete
});

it('empresa com filial, cliente ou fornecedor ativo não pode ser excluída (409)', function () {
    $uuid = regCompany($this->browser);
    $branch = $this->browser->post("/api/v1/companies/{$uuid}/branches", ['name' => 'Filial DEMO'])->assertCreated()->json('data.id');

    $this->browser->delete("/api/v1/companies/{$uuid}")->assertStatus(409)->assertJsonPath('error.code', 'company_in_use');
    $this->browser->delete("/api/v1/branches/{$branch}")->assertNoContent();
    $this->browser->delete("/api/v1/companies/{$uuid}")->assertNoContent();
});

// ---------------------------------------------------------------- isolamento
it('registros de outro tenant respondem 404 em leitura, edição, exclusão e sub-recursos', function () {
    $coB = regCompanyInB($this->B);
    $branchB = app(TenantContext::class)->run($this->B, fn () => $coB->branches()->create(['name' => 'Filial B']));
    $addrB = app(TenantContext::class)->run($this->B, fn () => $coB->addresses()->create(['street' => 'Rua B', 'city' => 'X', 'state' => 'MT']));
    $uuidA = regCompany($this->browser);

    $this->browser->get("/api/v1/companies/{$coB->uuid}")->assertNotFound();
    $this->browser->put("/api/v1/companies/{$coB->uuid}", ['trade_name' => 'invadido'])->assertNotFound();
    $this->browser->delete("/api/v1/companies/{$coB->uuid}")->assertNotFound();
    $this->browser->get("/api/v1/companies/{$coB->uuid}/branches")->assertNotFound();
    $this->browser->post("/api/v1/companies/{$coB->uuid}/branches", ['name' => 'x'])->assertNotFound();
    $this->browser->get("/api/v1/branches/{$branchB->id}")->assertNotFound();
    $this->browser->get("/api/v1/companies/{$coB->uuid}/addresses")->assertNotFound();
    // endereço de B pelo id, mas pendurado numa empresa de A: também 404
    $this->browser->put("/api/v1/companies/{$uuidA}/addresses/{$addrB->id}", ['street' => 'x'])->assertNotFound();
    $this->browser->delete("/api/v1/companies/{$uuidA}/addresses/{$addrB->id}")->assertNotFound();
    // listagem e filtro não vazam
    $this->browser->get('/api/v1/companies')->assertJsonPath('meta.total', 1);
    $this->browser->get("/api/v1/customers?company_uuid={$coB->uuid}")->assertJsonPath('meta.total', 0);
    // cliente não pode ser criado em empresa de outro tenant
    $this->browser->post('/api/v1/customers', ['company_uuid' => $coB->uuid, 'type' => 'person', 'name' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('company_uuid', 'error.details');

    expect(app(TenantContext::class)->run($this->B, fn () => Company::find($coB->id)->trade_name))->toBeNull();
});

// ---------------------------------------------------------------- permissões
it('viewer não vê cadastros; member só lê; admin gerencia', function () {
    $uuid = regCompany($this->browser);
    $viewer = Scenario::member($this->A, 'viewer');
    $member = Scenario::member($this->A, 'member');

    $v = new Browser($this);
    $v->login($viewer->email)->assertOk();
    $v->get('/api/v1/companies')->assertForbidden();

    $m = new Browser($this);
    $m->login($member->email)->assertOk();
    $m->get('/api/v1/companies')->assertOk();
    $m->get("/api/v1/companies/{$uuid}/contacts")->assertOk();
    $m->post('/api/v1/companies', ['legal_name' => 'x', 'document_number' => '61693043000194'])->assertForbidden();
    $m->post("/api/v1/companies/{$uuid}/contacts", ['name' => 'x'])->assertForbidden();
    $m->post('/api/v1/units', ['code' => 'm2', 'name' => 'metro quadrado'])->assertForbidden();
});

it('token: companies.view lê, mas não escreve; sem a ability, 403', function () {
    $uuid = regCompany($this->browser);
    $token = $this->browser->post('/api/v1/tokens', ['name' => 'erp', 'expires_in_days' => 30, 'abilities' => ['companies.view']])
        ->assertCreated()->json('token');

    $this->withToken($token)->getJson("/api/v1/companies/{$uuid}")->assertOk();
    $this->withToken($token)->putJson("/api/v1/companies/{$uuid}", ['trade_name' => 'x'])->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/customers')->assertForbidden();
});

// ---------------------------------------------------------------- clientes e fornecedores
it('cliente: CPF para pessoa, CNPJ para empresa; documento opcional; único por empresa', function () {
    $co = regCompany($this->browser);
    $base = ['company_uuid' => $co, 'name' => 'Cliente DEMO'];

    $this->browser->post('/api/v1/customers', $base + ['type' => 'person'])->assertCreated();
    $this->browser->post('/api/v1/customers', $base + ['type' => 'person', 'document_number' => CPF_OK])->assertCreated()
        ->assertJsonPath('data.document_number', '52998224725')->assertJsonPath('data.company_uuid', $co);
    $this->browser->post('/api/v1/customers', $base + ['type' => 'person', 'document_number' => CPF_OK])
        ->assertUnprocessable()->assertJsonValidationErrors('document_number', 'error.details');
    $this->browser->post('/api/v1/customers', $base + ['type' => 'company', 'document_number' => CPF_OK])
        ->assertUnprocessable()->assertJsonValidationErrors('document_number', 'error.details');
    $this->browser->post('/api/v1/customers', $base + ['type' => 'company', 'document_number' => CNPJ_A])->assertCreated();
});

it('fornecedor: documento obrigatório, pessoa física aceita (D-30); trocar o tipo exige documento compatível', function () {
    $co = regCompany($this->browser);
    $base = ['company_uuid' => $co, 'legal_name' => 'Fornecedor DEMO'];

    $this->browser->post('/api/v1/suppliers', $base + ['type' => 'company'])->assertUnprocessable()->assertJsonValidationErrors('document_number', 'error.details');
    $uuid = $this->browser->post('/api/v1/suppliers', $base + ['type' => 'person', 'document_number' => CPF_OK])->assertCreated()->json('data.uuid');

    $this->browser->put("/api/v1/suppliers/{$uuid}", ['type' => 'company'])->assertUnprocessable()->assertJsonValidationErrors('document_number', 'error.details');
    $this->browser->put("/api/v1/suppliers/{$uuid}", ['type' => 'company', 'document_number' => CNPJ_A])->assertOk()
        ->assertJsonPath('data.type', 'company');
    $this->browser->put("/api/v1/suppliers/{$uuid}", ['company_uuid' => $co])->assertUnprocessable(); // empresa não muda
    $this->browser->get('/api/v1/suppliers?q=fornecedor')->assertJsonPath('meta.total', 1);
    $this->browser->delete("/api/v1/suppliers/{$uuid}")->assertNoContent();
    $this->browser->get('/api/v1/suppliers')->assertJsonPath('meta.total', 0);
});

// ---------------------------------------------------------------- endereços e contatos
it('endereço: normaliza CEP/UF, recusa UF inválida e segundo endereço principal (409)', function () {
    $co = regCompany($this->browser);

    $this->browser->post("/api/v1/companies/{$co}/addresses", ['street' => 'R ANAMBE', 'number' => '103', 'city' => 'Bombinhas', 'state' => 'sc', 'postal_code' => '88.215-000'])
        ->assertCreated()->assertJsonPath('data.state', 'SC')->assertJsonPath('data.postal_code', '88215000')->assertJsonPath('data.kind', 'main');
    $this->browser->post("/api/v1/companies/{$co}/addresses", ['street' => 'x', 'city' => 'y', 'state' => 'SC'])
        ->assertStatus(409)->assertJsonPath('error.code', 'main_address_exists');
    $this->browser->post("/api/v1/companies/{$co}/addresses", ['street' => 'x', 'city' => 'y', 'state' => 'XX', 'kind' => 'billing'])
        ->assertUnprocessable()->assertJsonValidationErrors('state', 'error.details');
    $this->browser->post("/api/v1/companies/{$co}/addresses", ['street' => 'x', 'city' => 'y', 'state' => 'SC', 'kind' => 'billing'])->assertCreated();
    $this->browser->get("/api/v1/companies/{$co}")->assertJsonCount(2, 'data.addresses');
});

it('contato: marcar outro como principal rebaixa o anterior (no máximo um)', function () {
    $co = regCompany($this->browser);
    $c1 = $this->browser->post("/api/v1/companies/{$co}/contacts", ['name' => 'Ana DEMO', 'is_primary' => true])->assertCreated()->json('data.id');
    $this->browser->post("/api/v1/companies/{$co}/contacts", ['name' => 'Bruno DEMO', 'is_primary' => true])->assertCreated();

    $lista = $this->browser->get("/api/v1/companies/{$co}/contacts")->assertOk()->json('data');
    expect(collect($lista)->where('is_primary', true)->pluck('name')->all())->toBe(['Bruno DEMO']);

    $this->browser->put("/api/v1/companies/{$co}/contacts/{$c1}", ['is_primary' => true])->assertOk();
    $lista = $this->browser->get("/api/v1/companies/{$co}/contacts")->json('data');
    expect(collect($lista)->where('is_primary', true)->pluck('name')->all())->toBe(['Ana DEMO']);
});

it('endereços e contatos funcionam para filial, cliente e fornecedor com a permissão do dono', function () {
    $co = regCompany($this->browser);
    $branch = $this->browser->post("/api/v1/companies/{$co}/branches", ['name' => 'Filial'])->json('data.id');
    $cu = $this->browser->post('/api/v1/customers', ['company_uuid' => $co, 'type' => 'person', 'name' => 'C'])->json('data.uuid');
    $su = $this->browser->post('/api/v1/suppliers', ['company_uuid' => $co, 'type' => 'company', 'legal_name' => 'S', 'document_number' => CNPJ_A])->json('data.uuid');

    foreach (["branches/{$branch}", "customers/{$cu}", "suppliers/{$su}"] as $owner) {
        $id = $this->browser->post("/api/v1/{$owner}/addresses", ['street' => 'Rua', 'city' => 'Cuiabá', 'state' => 'MT'])->assertCreated()->json('data.id');
        $this->browser->put("/api/v1/{$owner}/addresses/{$id}", ['number' => '10'])->assertOk()->assertJsonPath('data.number', '10');
        $this->browser->post("/api/v1/{$owner}/contacts", ['name' => 'Contato'])->assertCreated();
        $this->browser->delete("/api/v1/{$owner}/addresses/{$id}")->assertNoContent();
    }
    $this->browser->get('/api/v1/branches/abc/addresses')->assertNotFound();
});

// ---------------------------------------------------------------- unidades
it('unidades: por tenant, código único, sem exclusão (desativar)', function () {
    $id = $this->browser->post('/api/v1/units', ['code' => 'm²', 'name' => 'metro quadrado'])->assertCreated()->json('data.id');
    $this->browser->post('/api/v1/units', ['code' => 'm²', 'name' => 'dup'])->assertUnprocessable();
    $this->browser->put("/api/v1/units/{$id}", ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
    $this->browser->get('/api/v1/units?status=active')->assertJsonCount(0, 'data');

    $b = new Browser($this);
    $b->login($this->adminB->email)->assertOk();
    $b->get('/api/v1/units')->assertJsonCount(0, 'data');
    $b->put("/api/v1/units/{$id}", ['name' => 'x'])->assertNotFound();
    $b->post('/api/v1/units', ['code' => 'm²', 'name' => 'metro quadrado'])->assertCreated();
});

// ---------------------------------------------------------------- auditoria
it('os eventos de cadastro (registry.*) são emitidos com entity_type do morph map e sem campos técnicos', function () {
    $co = regCompany($this->browser);
    $this->browser->post("/api/v1/companies/{$co}/contacts", ['name' => 'Ana', 'email' => 'ana@example.test'])->assertCreated();

    $log = AuditLog::query()->where('entity_type', 'company_contact')->firstOrFail();
    expect($log->event)->toBe(AuditEvent::REGISTRY_CREATED)
        ->and($log->user_id)->toBe($this->adminA->id)
        ->and($log->new_values)->toHaveKeys(['name', 'email'])
        ->and($log->new_values)->not->toHaveKeys(['tenant_id', 'primary_marker', 'id']);
});
