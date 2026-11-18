<?php

use App\Models\AuditLog;
use App\Models\ReferenceDataset;
use App\Models\Role;
use App\Modules\Audit\AuditEvent;
use App\Modules\Budgets\Report\ChromeRenderer;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\Browser;
use Tests\Support\Scenario;
use Tests\Support\SinapiFixture;

/* Fase 4B.6 — API de orçamentos: conteúdo, fluxo de aprovação (D-P34), permissões, acesso por obra, isolamento, auditoria. */

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
    $this->orc = $this->b->post('/api/v1/budgets', ['company_uuid' => $this->co, 'code' => 'ORC-1', 'title' => 'DEMO Residencial', 'mode' => 'reference', 'reference_dataset_id' => $this->ds])
        ->assertCreated()->json('data.uuid');
    $this->base = "/api/v1/budgets/{$this->orc}/versions/1";
});

function loginRole(object $t, string $role): Browser
{
    $b = new Browser($t);
    $b->login(Scenario::member($t->A, $role)->email)->assertOk();

    return $b;
}

function roleWith(object $t, string $name, array $perms): void
{
    app(TenantContext::class)->run($t->A, fn () => Role::create(['name' => $name, 'guard_name' => 'web'])->givePermissionTo($perms));
}

function preencher(object $t): int
{
    $g = $t->b->post("{$t->base}/groups", ['code' => '1', 'name' => 'DEMO Rede de água'])->assertCreated()->json('data.id');
    $t->b->post("{$t->base}/items/reference", ['reference_dataset_id' => $t->ds, 'type' => 'composition', 'code' => '97141', 'group_id' => $g, 'quantity' => '10'])->assertCreated();
    $t->b->post("{$t->base}/items", ['group_id' => $g, 'code' => '1.99', 'description' => 'DEMO Item manual', 'unit_code' => 'VB', 'quantity' => '1', 'unit_cost' => '100.00'])->assertCreated();

    return $g;
}

it('cria orçamento por referência (UF/regime da base), monta etapas e itens e devolve os totais', function () {
    $this->b->get("/api/v1/budgets/{$this->orc}")->assertOk()
        ->assertJsonPath('data.state', 'MT')->assertJsonPath('data.social_charges_regime', 'with_relief')
        ->assertJsonPath('data.current_version.number', 1)->assertJsonPath('data.current_version.status', 'draft')
        ->assertJsonPath('data.abilities', ['edit' => true, 'approve' => true])
        ->assertJsonMissingPath('data.id');

    $g = preencher($this);
    $this->b->put("{$this->base}/bdi", ['scope' => 'global', 'method' => 'informed', 'percent' => '25'])->assertOk()->assertJsonPath('totals.direct_cost', '138.40');
    $this->b->put("{$this->base}/bdi", ['scope' => 'group', 'group_id' => $g, 'method' => 'tcu',
        'components' => ['ac' => '4', 's' => '0.4', 'r' => '0.97', 'g' => '0.4', 'df' => '1.23', 'l' => '7.4', 'i' => '8.65']])->assertOk()->assertJsonPath('data.percent', '25.8800');
    $this->b->post("{$this->base}/indirect-costs", ['description' => 'DEMO Canteiro', 'method' => 'fixed', 'amount' => '50.00'])->assertCreated();
    $this->b->put($this->base, ['discount_percent' => '2'])->assertOk();

    $v = $this->b->get($this->base)->assertOk()->assertJsonCount(1, 'data.groups')->assertJsonCount(2, 'data.items')->json('data');
    // 138,40 × 1,2588 = 174,22 (38,40→48,34; 100→125,88); + 50 indiretos = 224,22; −2% = 4,48 → 219,74
    expect($v['totals'])->toMatchArray(['direct_cost' => '138.40', 'items_sale' => '174.22', 'indirect_cost' => '50.00', 'global_discount' => '4.48', 'final_price' => '219.74']);

    $this->b->get("{$this->base}/abc?type=resources")->assertOk()->assertJsonPath('data.0.code', '4750')->assertJsonPath('data.0.class', 'A');
    $item = collect($v['items'])->firstWhere('code', '97141');
    $this->b->get("{$this->base}/items/{$item['id']}/composition")->assertOk()
        ->assertJsonPath('data.composition.source_snapshot.source', 'SINAPI')->assertJsonCount(3, 'data.composition.items')->assertJsonCount(3, 'data.resources');
});

it('valida a entrada: precisão decimal, etapa de outra versão, modo referência sem base, código repetido', function () {
    $g = preencher($this);
    $this->b->post("{$this->base}/items", ['group_id' => $g, 'code' => 'x', 'description' => 'x', 'unit_code' => 'UN', 'quantity' => '1.12345', 'unit_cost' => '1.001'])
        ->assertUnprocessable()->assertJsonValidationErrors(['quantity', 'unit_cost'], 'error.details');
    $this->b->post("{$this->base}/items", ['group_id' => 999999, 'code' => 'x', 'description' => 'x', 'unit_code' => 'UN', 'quantity' => '1', 'unit_cost' => '1'])
        ->assertUnprocessable()->assertJsonValidationErrors('group_id', 'error.details');
    $this->b->post('/api/v1/budgets', ['company_uuid' => $this->co, 'code' => 'ORC-2', 'title' => 'x', 'mode' => 'reference'])
        ->assertUnprocessable()->assertJsonValidationErrors('reference_dataset_id', 'error.details');
    $this->b->post('/api/v1/budgets', ['company_uuid' => $this->co, 'code' => 'ORC-1', 'title' => 'x', 'mode' => 'manual'])
        ->assertUnprocessable()->assertJsonValidationErrors('code', 'error.details');
    $this->b->put("{$this->base}/bdi", ['scope' => 'group', 'method' => 'informed', 'percent' => '10'])->assertUnprocessable()->assertJsonValidationErrors('group_id', 'error.details');
    $this->b->put("{$this->base}/bdi", ['scope' => 'global', 'method' => 'tcu', 'components' => ['i' => '100']])->assertUnprocessable();
});

it('fluxo completo: enviar, devolver, rejeitar (com motivo), reabrir, aprovar (bloqueia), nova versão e substituição', function () {
    $wf = fn (string $a, array $d = []) => $this->b->post("/api/v1/budgets/{$this->orc}/workflow/{$a}", $d);

    $wf('submit')->assertUnprocessable()->assertJsonPath('error.code', 'empty_budget');
    $g = preencher($this);
    $wf('approve')->assertUnprocessable()->assertJsonPath('error.code', 'invalid_transition');
    $wf('submit')->assertOk()->assertJsonPath('data.status', 'in_review');
    $this->b->post("{$this->base}/groups", ['code' => '2', 'name' => 'x'])->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule'); // em revisão não edita
    $wf('withdraw')->assertOk()->assertJsonPath('data.status', 'draft');
    $wf('submit')->assertOk();
    $wf('reject')->assertUnprocessable()->assertJsonPath('error.code', 'comment_required');
    $wf('reject', ['comment' => 'DEMO Revisar quantidades'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $wf('reopen')->assertOk()->assertJsonPath('data.status', 'draft');
    $wf('submit')->assertOk();
    $wf('approve', ['comment' => 'DEMO ok'])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.locked', true);

    $this->b->post("{$this->base}/groups", ['code' => '2', 'name' => 'x'])->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');
    $this->b->delete("/api/v1/budgets/{$this->orc}")->assertStatus(409)->assertJsonPath('error.code', 'budget_approved');

    $hist = $this->b->get("/api/v1/budgets/{$this->orc}/history")->assertOk()->json('data');
    expect(collect($hist['status'])->pluck('to')->all())->toBe(['approved', 'in_review', 'draft', 'rejected', 'in_review', 'draft', 'in_review'])
        ->and(collect($hist['decisions'])->pluck('decision')->all())->toBe(['approved', 'rejected'])
        ->and($hist['decisions'][0]['totals_hash'])->toMatch('/^[0-9a-f]{64}$/');

    $this->b->post("/api/v1/budgets/{$this->orc}/versions", ['label' => 'Revisão'])->assertCreated()->assertJsonPath('data.number', 2);
    $this->b->post("/api/v1/budgets/{$this->orc}/versions/2/groups", ['code' => '2', 'name' => 'DEMO Nova etapa'])->assertCreated();
    $wf('submit')->assertOk();
    $wf('approve')->assertOk();
    $this->b->get("/api/v1/budgets/{$this->orc}")->assertOk()
        ->assertJsonPath('data.versions.0.status', 'superseded')->assertJsonPath('data.versions.1.status', 'approved');

    $eventos = AuditLog::query()->where('entity_type', 'budget')->pluck('event')->unique()->values()->all();
    expect($eventos)->toContain(AuditEvent::BUDGET_SUBMITTED, AuditEvent::BUDGET_WITHDRAWN, AuditEvent::BUDGET_REJECTED,
        AuditEvent::BUDGET_REOPENED, AuditEvent::BUDGET_APPROVED, AuditEvent::BUDGET_VERSION_CREATED);
});

it('permissões: member consulta mas não cria nem aprova; aprovador aprova sem poder editar', function () {
    preencher($this);
    $member = loginRole($this, 'member');
    $member->get("/api/v1/budgets/{$this->orc}")->assertOk()->assertJsonPath('data.abilities', ['edit' => false, 'approve' => false]);
    $member->post('/api/v1/budgets', ['company_uuid' => $this->co, 'code' => 'X', 'title' => 'x', 'mode' => 'manual'])->assertForbidden();
    $member->post("/api/v1/budgets/{$this->orc}/workflow/submit")->assertForbidden();

    roleWith($this, 'aprovador', ['budgets.view', 'budgets.approve']);
    $ap = loginRole($this, 'aprovador');
    $this->b->post("/api/v1/budgets/{$this->orc}/workflow/submit")->assertOk();
    $ap->post("{$this->base}/groups", ['code' => '9', 'name' => 'x'])->assertForbidden();
    $ap->post("/api/v1/budgets/{$this->orc}/workflow/withdraw")->assertForbidden(); // devolver é de quem edita
    $ap->post("/api/v1/budgets/{$this->orc}/workflow/approve")->assertOk()->assertJsonPath('data.status', 'approved');
});

it('orçamento de obra respeita o acesso por obra (D-P24): sem acesso → 404; nível view não edita', function () {
    $obra = $this->b->post('/api/v1/projects', ['company_uuid' => $this->co, 'code' => 'OB-1', 'name' => 'DEMO Obra'])->json('data.uuid');
    $orc = $this->b->post('/api/v1/budgets', ['company_uuid' => $this->co, 'code' => 'ORC-OB', 'title' => 'DEMO', 'mode' => 'manual', 'project_uuid' => $obra])
        ->assertCreated()->assertJsonPath('data.project.uuid', $obra)->json('data.uuid');

    roleWith($this, 'orcamentista', ['budgets.view', 'budgets.manage', 'projects.view']);
    $user = Scenario::member($this->A, 'orcamentista');
    $o = new Browser($this);
    $o->login($user->email)->assertOk();

    $o->get("/api/v1/budgets/{$orc}")->assertNotFound();
    $o->get('/api/v1/budgets')->assertOk()->assertJsonPath('meta.total', 1); // só o ORC-1 (sem obra)

    $this->b->post("/api/v1/projects/{$obra}/access", ['user_uuid' => $user->uuid, 'access_level' => 'view'])->assertOk();
    $o->get("/api/v1/budgets/{$orc}")->assertOk()->assertJsonPath('data.abilities.edit', false);
    $o->post("/api/v1/budgets/{$orc}/versions/1/groups", ['code' => '1', 'name' => 'x'])->assertForbidden();

    $this->b->post("/api/v1/projects/{$obra}/access", ['user_uuid' => $user->uuid, 'access_level' => 'edit'])->assertOk();
    $o->post("/api/v1/budgets/{$orc}/versions/1/groups", ['code' => '1', 'name' => 'DEMO'])->assertCreated();
});

it('isolamento: orçamento de outro tenant → 404 em todas as rotas; token só com budgets.view não aprova', function () {
    $g = preencher($this);
    $B = Scenario::tenant();
    $bb = new Browser($this);
    $bb->login(Scenario::member($B, 'admin')->email)->assertOk();

    $bb->get("/api/v1/budgets/{$this->orc}")->assertNotFound();
    $bb->get($this->base)->assertNotFound();
    $bb->post("{$this->base}/groups", ['code' => '1', 'name' => 'x'])->assertNotFound();
    $bb->post("/api/v1/budgets/{$this->orc}/workflow/submit")->assertNotFound();
    $bb->get('/api/v1/budgets')->assertOk()->assertJsonPath('meta.total', 0);

    $token = $this->b->post('/api/v1/tokens', ['name' => 'bi', 'expires_in_days' => 30, 'abilities' => ['budgets.view']])->assertCreated()->json('token');
    $this->withToken($token)->getJson($this->base)->assertOk();
    $this->withToken($token)->postJson("/api/v1/budgets/{$this->orc}/workflow/submit")->assertForbidden();
    $this->withToken($token)->postJson("{$this->base}/groups", ['code' => '1', 'name' => 'x'])->assertForbidden();
});

it('exclusão de rascunho funciona e todos os eventos da Fase 4 são emitidos por operações reais', function () {
    $outro = $this->b->post('/api/v1/budgets', ['company_uuid' => $this->co, 'code' => 'ORC-D', 'title' => 'x', 'mode' => 'manual'])->json('data.uuid');
    $this->b->delete("/api/v1/budgets/{$outro}")->assertNoContent();
    $this->b->get("/api/v1/budgets/{$outro}")->assertNotFound();

    preencher($this);
    foreach (['submit', 'withdraw', 'submit'] as $a) {
        $this->b->post("/api/v1/budgets/{$this->orc}/workflow/{$a}")->assertOk();
    }
    $this->b->post("/api/v1/budgets/{$this->orc}/workflow/reject", ['comment' => 'x'])->assertOk();
    $this->b->post("/api/v1/budgets/{$this->orc}/workflow/reopen")->assertOk();
    $this->b->post("/api/v1/budgets/{$this->orc}/workflow/submit")->assertOk();
    $this->b->post("/api/v1/budgets/{$this->orc}/workflow/approve")->assertOk();
    $this->b->post("/api/v1/budgets/{$this->orc}/versions")->assertCreated();
    $this->b->post("{$this->base}/report", ['format' => 'xlsx'])->assertOk();

    $emitidos = AuditLog::query()->distinct()->pluck('event')->all();
    // resource.imported é coberto em Feature/Catalog/ResourceCatalogTest
    expect(array_values(array_diff(AuditEvent::phase4(), [AuditEvent::RESOURCE_IMPORTED], $emitidos)))->toBe([]);
});

it('código vazio gera ORC-NNN/AAAA com o ano da data-base; numeração por ano; manual é respeitado', function () {
    $novo = fn (array $x) => $this->b->post('/api/v1/budgets', ['company_uuid' => $this->co, 'title' => 'DEMO', 'mode' => 'manual', ...$x])->assertCreated()->json('data.code');
    $ano = (int) now()->format('Y');

    expect($novo(['reference_date' => '2026-11-20']))->toBe('ORC-001/2026')
        ->and($novo(['reference_date' => '2026-12-01', 'code' => '']))->toBe('ORC-002/2026')
        ->and($novo(['reference_date' => '2027-02-01']))->toBe('ORC-001/2027')
        ->and($novo([]))->toBe(sprintf('ORC-%03d/%d', $ano === 2026 ? 3 : ($ano === 2027 ? 2 : 1), $ano))
        ->and($novo(['code' => 'ESPECIAL-1']))->toBe('ESPECIAL-1');

    $this->b->get('/api/v1/budgets/next-code?reference_date=2027-05-05')->assertOk()->assertJsonPath('data.code', 'ORC-002/2027');
    loginRole($this, 'member')->get('/api/v1/budgets/next-code')->assertForbidden();
});

/** Lê o .xlsx baixado: [nome da aba => linhas]. */
function lerXlsx(TestResponse $res): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'xt').'.xlsx';
    copy($res->baseResponse->getFile()->getPathname(), $tmp);
    $reader = new Reader;
    $reader->open($tmp);
    $out = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $out[$sheet->getName()][] = $row->toArray();
        }
    }
    $reader->close();
    @unlink($tmp);

    return $out;
}

it('relatório XLSX (4D): baixa com uma aba por parte, valores numéricos iguais à tela, opções e auditoria', function () {
    preencher($this);
    $totals = $this->b->get($this->base)->json('data.totals');

    $res = $this->b->post("{$this->base}/report", ['format' => 'xlsx', 'notes' => 'DEMO validade 30 dias'])->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('ORC-1-v1-orcamento.xlsx')
        ->and($res->headers->get('cache-control'))->toContain('no-store');
    $x = lerXlsx($res);
    expect(array_keys($x))->toBe(['Resumo', 'Sintético', 'Analítico', 'BDI', 'Curva ABC', 'Fechamento']);

    $final = collect($x['Sintético'])->first(fn ($r) => ($r[0] ?? null) === 'VALOR FINAL');
    expect(number_format((float) end($final), 2, '.', ''))->toBe($totals['final_price'])
        ->and(collect($x['Sintético'])->pluck(0)->all())->toContain('1.99')
        ->and(collect($x['Analítico'])->pluck(2)->all())->toContain('88316')
        ->and(collect($x['Fechamento'])->flatten()->all())->toContain('DEMO validade 30 dias');

    // ocultar o BDI: sem a coluna e sem a aba de BDI; só as partes pedidas
    $y = lerXlsx($this->b->post("{$this->base}/report", ['format' => 'xlsx', 'hide_bdi' => true, 'parts' => ['bdi', 'abc']])->assertOk());
    expect(array_keys($y))->toBe(['Resumo', 'Sintético', 'Curva ABC'])
        ->and(collect($y['Sintético'])->first(fn ($r) => ($r[0] ?? null) === 'Item'))->not->toContain('BDI');

    $log = AuditLog::query()->where('event', AuditEvent::BUDGET_REPORT_EXPORTED)->latest('id')->first();
    expect($log->metadata)->toMatchArray(['version' => 1, 'format' => 'xlsx', 'parts' => ['abc'], 'hide_bdi' => true]);

    // validação: formato ainda indisponível, parte desconhecida
    $this->b->post("{$this->base}/report", ['format' => 'odt'])->assertUnprocessable();
    $this->b->post("{$this->base}/report", ['format' => 'xlsx', 'parts' => ['tudo']])->assertUnprocessable();
});

it('relatório (4D): member com budgets.view gera; outro tenant → 404; sem acesso à obra → 404', function () {
    preencher($this);
    loginRole($this, 'member')->post("{$this->base}/report", ['format' => 'xlsx'])->assertOk();

    $bb = new Browser($this);
    $bb->login(Scenario::member(Scenario::tenant(), 'admin')->email)->assertOk();
    $bb->post("{$this->base}/report", ['format' => 'xlsx'])->assertNotFound();

    roleWith($this, 'sem-orc', ['projects.view']);
    $u = Scenario::member($this->A, 'sem-orc');
    $o = new Browser($this);
    $o->login($u->email)->assertOk();
    $o->post("{$this->base}/report", ['format' => 'xlsx'])->assertForbidden();
});

it('relatório PDF (4D) pela API; o XLSX leva os gráficos da curva ABC como imagem', function () {
    if (! app(ChromeRenderer::class)->available()) {
        $this->markTestSkipped('Chrome não instalado neste ambiente.');
    }
    preencher($this);
    $pdf = $this->b->post("{$this->base}/report", ['format' => 'pdf', 'parts' => ['abc']])->assertOk();
    expect($pdf->headers->get('content-type'))->toBe('application/pdf')
        ->and(substr(file_get_contents($pdf->baseResponse->getFile()->getPathname()), 0, 5))->toBe('%PDF-');

    $xlsx = $this->b->post("{$this->base}/report", ['format' => 'xlsx', 'parts' => ['abc']])->assertOk();
    $zip = new ZipArchive;
    $zip->open($xlsx->baseResponse->getFile()->getPathname());
    $media = array_filter(array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1)), fn ($n) => str_starts_with($n, 'xl/media/'));
    $zip->close();
    expect(count($media))->toBe(3); // a marca na aba Resumo + os 2 gráficos
});

it('relatório DOCX (4D) pela API: gráficos e marca d\'água como imagem quando há Chrome', function () {
    preencher($this);
    $res = $this->b->post("{$this->base}/report", ['format' => 'docx'])->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('ORC-1-v1-orcamento.docx');
    $zip = new ZipArchive;
    $zip->open($res->baseResponse->getFile()->getPathname());
    $media = array_filter(array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1)), fn ($n) => str_starts_with($n, 'word/media/'));
    $zip->close();
    $chrome = app(ChromeRenderer::class)->available();
    expect(count($media))->toBeGreaterThanOrEqual($chrome ? 4 : 1); // marca ObraFlow + 2 gráficos + marca d'água
});
