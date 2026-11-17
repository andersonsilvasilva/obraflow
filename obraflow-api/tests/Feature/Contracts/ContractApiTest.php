<?php

use App\Models\AuditLog;
use App\Modules\Audit\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;

/* Fase 5A — contratos da obra (FASE5_MODELO.md, D-P44…D-P56, A-45). Dados "DEMO"; valores conferidos à mão. */

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->admin = Scenario::member($this->A, 'admin');
    $this->b = new Browser($this);
    $this->b->login($this->admin->email)->assertOk();
    $this->co = $this->b->post('/api/v1/companies', ['legal_name' => 'DEMO Construtora', 'document_number' => '11222333000181'])->json('data.uuid');
    $this->co2 = $this->b->post('/api/v1/companies', ['legal_name' => 'DEMO Outra', 'document_number' => '11444777000161'])->json('data.uuid');
    $this->cli = $this->b->post('/api/v1/customers', ['company_uuid' => $this->co, 'type' => 'person', 'name' => 'DEMO Cliente'])->json('data.uuid');
    $this->forn = $this->b->post('/api/v1/suppliers', ['company_uuid' => $this->co, 'type' => 'company', 'document_number' => '11222333000181', 'legal_name' => 'DEMO Empreiteira'])->json('data.uuid');
    $this->cliOutra = $this->b->post('/api/v1/customers', ['company_uuid' => $this->co2, 'type' => 'person', 'name' => 'DEMO Outro'])->json('data.uuid');
    $this->obra = $this->b->post('/api/v1/projects', ['company_uuid' => $this->co, 'name' => 'DEMO Obra'])->assertCreated()->json('data.uuid');
});

function contrato(object $t, array $extra = []): string
{
    return $t->b->post('/api/v1/contracts', ['project_uuid' => $t->obra, 'kind' => 'customer', 'customer_uuid' => $t->cli,
        'object' => 'DEMO Execução da obra', 'starts_at' => '2026-03-01', 'original_end_date' => '2026-12-31', ...$extra])->assertCreated()->json('data.uuid');
}

/** Contrato ativo com 2 itens: 10 m² × 50,00 = 500,00 e 1 VB × 1.000,00 = 1.000,00 → 1.500,00. @return array{0: string, 1: int, 2: int} */
function contratoAtivo(object $t): array
{
    $c = contrato($t);
    $i1 = $t->b->post("/api/v1/contracts/{$c}/items", ['description' => 'DEMO Alvenaria', 'unit_code' => 'm²', 'quantity' => '10', 'unit_price' => '50.00'])->json('data.id');
    $i2 = $t->b->post("/api/v1/contracts/{$c}/items", ['description' => 'DEMO Mobilização', 'unit_code' => 'VB', 'quantity' => '1', 'unit_price' => '1000.00'])->json('data.id');
    $t->b->post("/api/v1/contracts/{$c}/workflow/activate", ['signed_at' => '2026-02-20'])->assertOk();

    return [$c, $i1, $i2];
}

it('cria contrato na obra com código automático; tipo exige a parte certa, da mesma empresa', function () {
    $c = $this->b->post('/api/v1/contracts', ['project_uuid' => $this->obra, 'kind' => 'customer', 'customer_uuid' => $this->cli,
        'object' => 'DEMO Execução', 'starts_at' => '2026-03-01', 'original_end_date' => '2026-12-31', 'retention_percent' => '5'])
        ->assertCreated()->assertJsonPath('data.code', 'CTR-001/2026')->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.project.uuid', $this->obra)->assertJsonPath('data.company.uuid', $this->co)
        ->assertJsonPath('data.current_end_date', '2026-12-31')->assertJsonPath('data.retention_percent', '5.0000')
        ->assertJsonPath('data.abilities.edit', true)->assertJsonMissingPath('data.id')->json('data.uuid');

    $base = ['project_uuid' => $this->obra, 'object' => 'x', 'starts_at' => '2026-03-01'];
    $this->b->post('/api/v1/contracts', $base + ['kind' => 'customer'])->assertUnprocessable()->assertJsonValidationErrors('customer_uuid', 'error.details');
    $this->b->post('/api/v1/contracts', $base + ['kind' => 'customer', 'customer_uuid' => $this->cli, 'supplier_uuid' => $this->forn])->assertUnprocessable();
    $this->b->post('/api/v1/contracts', $base + ['kind' => 'customer', 'customer_uuid' => $this->cliOutra])->assertUnprocessable()->assertJsonValidationErrors('customer_uuid', 'error.details');
    $this->b->post('/api/v1/contracts', $base + ['kind' => 'supplier', 'supplier_uuid' => $this->forn])->assertCreated()->assertJsonPath('data.code', 'CTR-002/2026')
        ->assertJsonPath('data.supplier.name', 'DEMO Empreiteira');
    $this->b->post('/api/v1/contracts', $base + ['kind' => 'customer', 'customer_uuid' => $this->cli, 'original_end_date' => '2026-01-01'])->assertUnprocessable();
    $this->b->post('/api/v1/contracts', ['kind' => 'customer', 'customer_uuid' => $this->cli, 'object' => 'x', 'starts_at' => '2026-03-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('project_uuid', 'error.details'); // obra obrigatória (A-45)

    $this->b->put("/api/v1/contracts/{$c}", ['kind' => 'supplier'])->assertUnprocessable(); // tipo fixo
    $this->b->get("/api/v1/contracts?project_uuid={$this->obra}")->assertOk()->assertJsonPath('meta.total', 2);
});

it('itens em rascunho: total = quantidade × preço (arredondado); ativar exige assinatura e itens; depois, itens travados', function () {
    $c = contrato($this);
    $this->b->post("/api/v1/contracts/{$c}/workflow/activate")->assertUnprocessable()->assertJsonPath('error.code', 'signature_required');
    $this->b->post("/api/v1/contracts/{$c}/workflow/activate", ['signed_at' => '2026-02-20'])->assertUnprocessable()->assertJsonPath('error.code', 'empty_contract');

    $id = $this->b->post("/api/v1/contracts/{$c}/items", ['code' => '1.1', 'description' => 'DEMO Concreto', 'unit_code' => 'm³', 'quantity' => '3.333', 'unit_price' => '412.37'])
        ->assertCreated()->assertJsonPath('data.total', '1374.43')->json('data.id'); // 3,333 × 412,37 = 1.374,4292
    $this->b->post("/api/v1/contracts/{$c}/items", ['description' => 'x', 'unit_code' => 'un', 'quantity' => '0', 'unit_price' => '1'])->assertUnprocessable();
    $this->b->put("/api/v1/contracts/{$c}/items/{$id}", ['quantity' => '4'])->assertOk()->assertJsonPath('data.total', '1649.48');
    $this->b->get("/api/v1/contracts/{$c}")->assertJsonPath('data.original_value', '1649.48')->assertJsonPath('data.current_value', '1649.48');

    $this->b->post("/api/v1/contracts/{$c}/workflow/activate", ['signed_at' => '2026-02-20'])->assertOk()
        ->assertJsonPath('data.status', 'active')->assertJsonPath('data.signed_at', '2026-02-20')->assertJsonPath('data.original_value', '1649.48');

    $this->b->put("/api/v1/contracts/{$c}/items/{$id}", ['quantity' => '5'])->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');
    $this->b->post("/api/v1/contracts/{$c}/items", ['description' => 'x', 'unit_code' => 'un', 'quantity' => '1', 'unit_price' => '1'])->assertUnprocessable();
    $this->b->delete("/api/v1/contracts/{$c}/items/{$id}")->assertUnprocessable();
    $this->b->put("/api/v1/contracts/{$c}", ['object' => 'mudou'])->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');
    $this->b->put("/api/v1/contracts/{$c}", ['notes' => 'DEMO observação'])->assertOk()->assertJsonPath('data.notes', 'DEMO observação');
});

it('gera itens do orçamento APROVADO: preço de venda com desconto global repartido, indiretos como item e a diferença de centavos', function () {
    $orc = $this->b->post('/api/v1/budgets', ['company_uuid' => $this->co, 'project_uuid' => $this->obra, 'title' => 'DEMO', 'mode' => 'manual'])->assertCreated()->json('data.uuid');
    $base = "/api/v1/budgets/{$orc}/versions/1";
    $g = $this->b->post("{$base}/groups", ['code' => '1', 'name' => 'DEMO Etapa'])->json('data.id');
    $this->b->post("{$base}/items", ['group_id' => $g, 'code' => 'A', 'description' => 'DEMO A', 'unit_code' => 'm²', 'quantity' => '3', 'unit_cost' => '10.01'])->assertCreated();
    $this->b->post("{$base}/items", ['group_id' => $g, 'code' => 'B', 'description' => 'DEMO B', 'unit_code' => 'VB', 'quantity' => '1', 'unit_cost' => '100.00'])->assertCreated();
    $this->b->put("{$base}/bdi", ['scope' => 'global', 'method' => 'informed', 'percent' => '25'])->assertOk();
    $this->b->post("{$base}/indirect-costs", ['description' => 'DEMO Canteiro', 'method' => 'fixed', 'amount' => '50.00'])->assertCreated();
    $this->b->put($base, ['discount_percent' => '2'])->assertOk();
    // A: 30,03 × 1,25 = 37,54; B: 125,00; + 50 = 212,54; −2% (4,25) = 208,29
    expect($this->b->get($base)->json('data.totals.final_price'))->toBe('208.29');

    $c = contrato($this);
    $this->b->post("/api/v1/contracts/{$c}/items/from-budget", ['budget_uuid' => $orc, 'version' => 1])->assertUnprocessable(); // ainda não aprovado
    $this->b->post("/api/v1/budgets/{$orc}/workflow/submit")->assertOk();
    $this->b->post("/api/v1/budgets/{$orc}/workflow/approve")->assertOk();

    // A: 37,54 × 0,98 ÷ 3 = 12,26 → 36,78; B: 125 × 0,98 = 122,50; indiretos 50 × 0,98 = 49,00 → 208,28 (1 centavo de diferença)
    $this->b->post("/api/v1/contracts/{$c}/items/from-budget", ['budget_uuid' => $orc, 'version' => 1])->assertCreated()
        ->assertJsonPath('data.items', 3)->assertJsonPath('data.contract_total', '208.28')
        ->assertJsonPath('data.budget_final_price', '208.29')->assertJsonPath('data.difference', '0.01');
    $itens = $this->b->get("/api/v1/contracts/{$c}/items")->assertOk()->json('data');
    expect(array_column($itens, 'unit_price'))->toBe(['12.26', '122.50', '49.00'])
        ->and(array_column($itens, 'from_budget'))->toBe([true, true, false])->and($itens[2]['unit_code'])->toBe('VB');
    $this->b->get("/api/v1/contracts/{$c}")->assertJsonPath('data.budget.code', $this->b->get("/api/v1/budgets/{$orc}")->json('data.code'));
    $this->b->post("/api/v1/contracts/{$c}/items/from-budget", ['budget_uuid' => $orc, 'version' => 1])->assertUnprocessable(); // já tem itens

    $outraObra = $this->b->post('/api/v1/projects', ['company_uuid' => $this->co, 'name' => 'DEMO Outra obra'])->json('data.uuid');
    $c2 = contrato($this, ['project_uuid' => $outraObra]);
    $this->b->post("/api/v1/contracts/{$c2}/items/from-budget", ['budget_uuid' => $orc, 'version' => 1])->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');
});

it('aditivos: quantidade e item novo mudam o valor atual exato; prazo muda o término; aviso acima de 25%; aprovado é imutável', function () {
    [$c, $alv] = contratoAtivo($this);

    $this->b->post("/api/v1/contracts/{$c}/addenda", ['kind' => 'value', 'description' => 'DEMO Acréscimo'])->assertCreated()->assertJsonPath('data.number', 1);
    $this->b->post("/api/v1/contracts/{$c}/addenda/1/approve", ['signed_at' => '2026-05-01'])->assertUnprocessable(); // sem linhas
    $this->b->post("/api/v1/contracts/{$c}/addenda/1/lines", ['line_type' => 'change', 'contract_item_id' => $alv, 'quantity_delta' => '-11'])->assertUnprocessable(); // ficaria negativa
    $this->b->post("/api/v1/contracts/{$c}/addenda/1/lines", ['line_type' => 'change', 'contract_item_id' => $alv, 'quantity_delta' => '2.5'])
        ->assertCreated()->assertJsonPath('data.value_delta', '125.00')->assertJsonPath('data.over_25_percent', false); // 2,5 × 50
    $this->b->post("/api/v1/contracts/{$c}/addenda/1/lines", ['line_type' => 'new', 'description' => 'DEMO Muro', 'unit_code' => 'm', 'unit_price' => '80.00', 'quantity_delta' => '4'])
        ->assertCreated()->assertJsonPath('data.value_delta', '445.00')->assertJsonPath('data.over_25_percent', true); // 125 + 320 > 375 (25% de 1.500)

    $this->b->post("/api/v1/contracts/{$c}/addenda/1/approve", ['signed_at' => '2026-05-01'])->assertOk()
        ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.value_delta', '445.00')->assertJsonPath('data.over_25_percent', true);
    $this->b->get("/api/v1/contracts/{$c}")->assertJsonPath('data.original_value', '1500.00')->assertJsonPath('data.current_value', '1945.00');
    $itens = collect($this->b->get("/api/v1/contracts/{$c}/items")->json('data'));
    expect($itens->firstWhere('id', $alv))->toMatchArray(['original_quantity' => '10.0000', 'quantity' => '12.5000', 'total' => '625.00'])
        ->and($itens->firstWhere('description', 'DEMO Muro'))->toMatchArray(['original_quantity' => '0.0000', 'quantity' => '4.0000', 'addendum' => 1]);

    $this->b->post("/api/v1/contracts/{$c}/addenda/1/lines", ['line_type' => 'change', 'contract_item_id' => $alv, 'quantity_delta' => '1'])->assertUnprocessable();
    $this->b->put("/api/v1/contracts/{$c}/addenda/1", ['description' => 'x'])->assertUnprocessable();

    // prazo
    $this->b->post("/api/v1/contracts/{$c}/addenda", ['kind' => 'term', 'description' => 'DEMO Prorrogação'])->assertCreated()->assertJsonPath('data.number', 2);
    $this->b->post("/api/v1/contracts/{$c}/addenda/2/approve", ['signed_at' => '2026-06-01'])->assertUnprocessable(); // sem data
    $this->b->put("/api/v1/contracts/{$c}/addenda/2", ['new_end_date' => '2027-03-31'])->assertOk();
    $this->b->post("/api/v1/contracts/{$c}/addenda/2/approve", ['signed_at' => '2026-06-01'])->assertOk()->assertJsonPath('data.value_delta', '0.00');
    $this->b->get("/api/v1/contracts/{$c}")->assertJsonPath('data.current_end_date', '2027-03-31')->assertJsonPath('data.original_end_date', '2026-12-31');

    // cancelar rascunho
    $this->b->post("/api/v1/contracts/{$c}/addenda", ['kind' => 'scope', 'description' => 'DEMO Desistido'])->assertCreated();
    $this->b->post("/api/v1/contracts/{$c}/addenda/3/cancel", ['reason' => 'DEMO'])->assertOk()->assertJsonPath('data.status', 'canceled');
    $this->b->get("/api/v1/contracts/{$c}/addenda")->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.lines.1.description', 'DEMO Muro');
});

it('ciclo de vida: suspender e cancelar exigem motivo; aditivo pendente impede concluir; só ativo recebe aditivo; histórico', function () {
    [$c] = contratoAtivo($this);
    $this->b->post("/api/v1/contracts/{$c}/workflow/suspend")->assertUnprocessable()->assertJsonPath('error.code', 'reason_required');
    $this->b->post("/api/v1/contracts/{$c}/workflow/suspend", ['reason' => 'DEMO Chuvas'])->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->b->post("/api/v1/contracts/{$c}/addenda", ['kind' => 'term', 'description' => 'x'])->assertUnprocessable(); // suspenso
    $this->b->post("/api/v1/contracts/{$c}/workflow/activate", ['signed_at' => '2026-02-20'])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_transition');
    $this->b->post("/api/v1/contracts/{$c}/workflow/resume")->assertOk()->assertJsonPath('data.status', 'active');

    $this->b->post("/api/v1/contracts/{$c}/addenda", ['kind' => 'term', 'description' => 'x'])->assertCreated();
    $this->b->post("/api/v1/contracts/{$c}/workflow/complete")->assertUnprocessable()->assertJsonPath('error.code', 'pending_addendum');
    $this->b->post("/api/v1/contracts/{$c}/addenda/1/cancel")->assertOk();
    $this->b->post("/api/v1/contracts/{$c}/workflow/complete")->assertOk()->assertJsonPath('data.status', 'completed');
    $this->b->post("/api/v1/contracts/{$c}/workflow/cancel", ['reason' => 'x'])->assertUnprocessable();
    $this->b->put("/api/v1/contracts/{$c}", ['notes' => 'x'])->assertUnprocessable();

    expect(array_column($this->b->get("/api/v1/contracts/{$c}/history")->assertOk()->json('data'), 'to'))->toBe(['completed', 'active', 'suspended', 'active']);

    $rascunho = contrato($this);
    $this->b->post("/api/v1/contracts/{$rascunho}/workflow/cancel")->assertUnprocessable();
    $this->b->post("/api/v1/contracts/{$rascunho}/workflow/cancel", ['reason' => 'DEMO Desistência'])->assertOk()->assertJsonPath('data.status', 'canceled');
    $this->b->delete("/api/v1/contracts/{$rascunho}")->assertStatus(405); // nunca se exclui contrato
});

it('acesso: contracts.* E acesso à obra; sem acesso → 404; view não altera; outro tenant não vê', function () {
    [$c] = contratoAtivo($this);
    $member = Scenario::member($this->A, 'member'); // member tem contracts.view (catálogo), sem acesso à obra
    $mb = new Browser($this);
    $mb->login($member->email)->assertOk();
    $mb->get("/api/v1/contracts/{$c}")->assertNotFound();
    $mb->get('/api/v1/contracts')->assertOk()->assertJsonPath('meta.total', 0);

    $this->b->post("/api/v1/projects/{$this->obra}/access", ['user_uuid' => $member->uuid, 'access_level' => 'edit'])->assertOk();
    $mb->get("/api/v1/contracts/{$c}")->assertOk()->assertJsonPath('data.abilities.edit', false); // sem contracts.manage
    $mb->get("/api/v1/contracts/{$c}/items")->assertOk()->assertJsonCount(2, 'data');
    $mb->put("/api/v1/contracts/{$c}", ['notes' => 'x'])->assertForbidden();
    $mb->post('/api/v1/contracts', ['project_uuid' => $this->obra, 'kind' => 'customer', 'customer_uuid' => $this->cli, 'object' => 'x', 'starts_at' => '2026-03-01'])->assertForbidden();

    $B = Scenario::tenant();
    $ob = new Browser($this);
    $ob->login(Scenario::member($B, 'admin')->email)->assertOk();
    $ob->get("/api/v1/contracts/{$c}")->assertNotFound();
    $ob->get('/api/v1/contracts')->assertOk()->assertJsonPath('meta.total', 0);
    $ob->post('/api/v1/contracts', ['project_uuid' => $this->obra, 'kind' => 'customer', 'customer_uuid' => $this->cli, 'object' => 'x', 'starts_at' => '2026-03-01'])->assertUnprocessable();
});

it('documentos do contrato e do aditivo (D-P55): vínculo conferido pelo acesso ao contrato', function () {
    [$c, $alv] = contratoAtivo($this);
    $this->b->post("/api/v1/contracts/{$c}/addenda", ['kind' => 'value', 'description' => 'DEMO'])->assertCreated();
    $addendumId = DB::table('contract_addenda')->value('id');

    $doc = $this->b->post('/api/v1/documents', ['title' => 'DEMO Contrato assinado'])->assertCreated()->json('data.uuid');
    $this->b->post("/api/v1/documents/{$doc}/links", ['linkable_type' => 'contract', 'linkable_key' => $c])->assertCreated();
    $docAd = $this->b->post('/api/v1/documents', ['title' => 'DEMO Aditivo 1'])->json('data.uuid');
    $this->b->post("/api/v1/documents/{$docAd}/links", ['linkable_type' => 'contract_addendum', 'linkable_key' => (string) $addendumId])->assertCreated();

    $this->b->get("/api/v1/documents?linkable_type=contract&linkable_key={$c}")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.uuid', $doc);
    $this->b->get("/api/v1/documents?linkable_type=contract_addendum&linkable_key={$addendumId}")->assertOk()->assertJsonPath('data.0.uuid', $docAd);
    $this->b->get("/api/v1/contracts/{$c}")->assertJsonPath('data.documents_count', 1);
    $this->b->get('/api/v1/contracts')->assertJsonPath('data.0.documents_count', 1);

    $member = Scenario::member($this->A, 'member');
    $mb = new Browser($this);
    $mb->login($member->email)->assertOk();
    // vê documentos (documents.view), mas não o contrato (sem acesso à obra): a busca pelo contrato não revela nada
    $mb->get("/api/v1/documents?linkable_type=contract&linkable_key={$c}")->assertOk()->assertJsonPath('meta.total', 0);
});

it('banco: parte coerente com o tipo, retenção < 100% e ativo exige assinatura (CHECKs); eventos da Fase 5 auditados', function () {
    [$c] = contratoAtivo($this);
    $id = DB::table('contracts')->where('uuid', $c)->value('id');
    expect(fn () => DB::table('contracts')->where('id', $id)->update(['supplier_id' => DB::table('suppliers')->value('id')]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('contracts')->where('id', $id)->update(['retention_percent' => 100]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('contracts')->where('id', $id)->update(['signed_at' => null]))->toThrow(QueryException::class);

    $orc = $this->b->post('/api/v1/budgets', ['company_uuid' => $this->co, 'project_uuid' => $this->obra, 'title' => 'DEMO', 'mode' => 'manual'])->json('data.uuid');
    $g = $this->b->post("/api/v1/budgets/{$orc}/versions/1/groups", ['code' => '1', 'name' => 'x'])->json('data.id');
    $this->b->post("/api/v1/budgets/{$orc}/versions/1/items", ['group_id' => $g, 'code' => 'A', 'description' => 'x', 'unit_code' => 'un', 'quantity' => '1', 'unit_cost' => '1'])->assertCreated();
    $this->b->post("/api/v1/budgets/{$orc}/workflow/submit")->assertOk();
    $this->b->post("/api/v1/budgets/{$orc}/workflow/approve")->assertOk();
    $this->b->post('/api/v1/contracts/'.contrato($this).'/items/from-budget', ['budget_uuid' => $orc, 'version' => 1])->assertCreated();
    $this->b->post("/api/v1/contracts/{$c}/addenda", ['kind' => 'term', 'description' => 'x', 'new_end_date' => '2027-01-31'])->assertCreated();
    $this->b->post("/api/v1/contracts/{$c}/addenda/1/approve", ['signed_at' => '2026-06-01'])->assertOk();
    $this->b->post("/api/v1/contracts/{$c}/addenda", ['kind' => 'term', 'description' => 'x'])->assertCreated();
    $this->b->post("/api/v1/contracts/{$c}/addenda/2/cancel")->assertOk();

    $emitidos = AuditLog::query()->distinct()->pluck('event')->all();
    expect(array_values(array_diff(AuditEvent::phase5(), $emitidos)))->toBe([])
        ->and(AuditLog::query()->whereIn('event', AuditEvent::phase5())->pluck('tenant_id')->unique()->all())->toBe([$this->A->id])
        ->and(AuditLog::query()->where('event', AuditEvent::REGISTRY_CREATED)->where('entity_type', 'contract_item')->exists())->toBeTrue();
});
