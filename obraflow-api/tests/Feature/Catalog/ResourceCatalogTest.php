<?php

use App\Models\AuditLog;
use App\Models\Resource;
use App\Models\Tenant;
use App\Modules\Audit\AuditEvent;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\Browser;
use Tests\Support\Fx;
use Tests\Support\Scenario;
use Tests\Support\XlsxFixture;

/* Fase 4C — catálogo de insumos da organização (D-P37…D-P43). Valores de controle conferidos à parte (Decimal). */

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->admin = Scenario::member($this->A, 'admin');
    $this->b = new Browser($this);
    $this->b->login($this->admin->email)->assertOk();
    $this->un = $this->b->post('/api/v1/units', ['code' => 'm³', 'name' => 'metro cúbico'])->assertCreated()->json('data.id');
    $this->h = $this->b->post('/api/v1/units', ['code' => 'h', 'name' => 'hora'])->assertCreated()->json('data.id');
});

function grupo(Browser $b, string $kind, string $name, ?int $parent = null): int
{
    return $b->post('/api/v1/catalog-groups', ['kind' => $kind, 'name' => $name, 'parent_id' => $parent])->assertCreated()->json('data.id');
}

it('cadastra material com grupo/subgrupo do mesmo tipo; código único; vínculos coerentes com o tipo', function () {
    $g = grupo($this->b, 'material', 'DEMO Agregados');
    $sub = grupo($this->b, 'material', 'DEMO Areia', $g);
    $gLabor = grupo($this->b, 'labor', 'DEMO Oficiais');

    $uuid = $this->b->post('/api/v1/resources', ['kind' => 'material', 'code' => 'MAT-1', 'name' => 'DEMO Areia média', 'unit_id' => $this->un, 'group_id' => $sub, 'brand' => 'DEMO', 'price' => '120.50'])
        ->assertCreated()->assertJsonPath('data.group.id', $sub)->assertJsonPath('data.unit.code', 'm³')->assertJsonPath('data.price', '120.50')->json('data.uuid');

    $this->b->post('/api/v1/resources', ['kind' => 'material', 'name' => 'x', 'unit_id' => $this->un, 'group_id' => $gLabor])->assertUnprocessable()->assertJsonValidationErrors('group_id', 'error.details');
    $this->b->post('/api/v1/resources', ['kind' => 'material', 'code' => 'MAT-1', 'name' => 'x', 'unit_id' => $this->un])->assertUnprocessable()->assertJsonValidationErrors('code', 'error.details');
    $this->b->post('/api/v1/resources', ['kind' => 'material', 'name' => 'x', 'unit_id' => $this->un, 'job_title_id' => 1])->assertUnprocessable()->assertJsonValidationErrors('job_title_id', 'error.details');

    $this->b->get('/api/v1/resources?kind=material&parent_group_id='.$g)->assertOk()->assertJsonPath('meta.total', 1);
    $this->b->get('/api/v1/resources?q=areia')->assertOk()->assertJsonPath('data.0.uuid', $uuid);
    $this->b->put("/api/v1/resources/{$uuid}", ['price' => '130.00', 'kind' => 'service'])->assertUnprocessable(); // tipo não muda
    $this->b->put("/api/v1/resources/{$uuid}", ['price' => '130.00'])->assertOk()->assertJsonPath('data.price', '130.00');
    $this->b->delete("/api/v1/catalog-groups/{$sub}")->assertStatus(409)->assertJsonPath('error.code', 'in_use');
    $this->b->delete("/api/v1/resources/{$uuid}")->assertNoContent();
});

it('grupos do catálogo exigem tipo e não se misturam com os de equipamento', function () {
    $this->b->post('/api/v1/catalog-groups', ['name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('kind', 'error.details');
    $this->b->post('/api/v1/catalog-groups', ['kind' => 'equipment', 'name' => 'x'])->assertUnprocessable();
    $eq = $this->b->post('/api/v1/resource-groups', ['name' => 'DEMO Andaimes'])->assertCreated()->json('data.id');
    $mat = grupo($this->b, 'material', 'DEMO Cimento');

    $this->b->get('/api/v1/catalog-groups')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mat);
    $this->b->get('/api/v1/resource-groups')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $eq);
    $this->b->put("/api/v1/catalog-groups/{$eq}", ['name' => 'x'])->assertNotFound();
});

it('balanço da mão de obra: preço/hora = salário × (1+encargos) × (1+adm) ÷ horas; vigência define o preço', function () {
    $cargo = $this->b->post('/api/v1/job-titles', ['name' => 'DEMO Pedreiro'])->json('data.id');
    $uuid = $this->b->post('/api/v1/resources', ['kind' => 'labor', 'name' => 'DEMO Pedreiro (h)', 'unit_id' => $this->h, 'job_title_id' => $cargo])->assertCreated()->json('data.uuid');

    $this->b->post("/api/v1/resources/{$uuid}/labor-costs", ['effective_date' => '2026-01-01', 'base_salary' => '2000.00', 'charges_percent' => '80', 'admin_percent' => '10', 'monthly_hours' => '220', 'labor_type' => 'direct', 'contract_type' => 'monthly'])
        ->assertCreated()->assertJsonPath('data.hourly_cost', '18.00')->assertJsonPath('resource.price', '18.00')->assertJsonPath('resource.price_date', '2026-01-01');
    $id2 = $this->b->post("/api/v1/resources/{$uuid}/labor-costs", ['effective_date' => '2026-06-01', 'base_salary' => '2537.40', 'charges_percent' => '79.65', 'admin_percent' => '15', 'monthly_hours' => '220'])
        ->assertCreated()->assertJsonPath('data.hourly_cost', '23.83')->assertJsonPath('resource.price', '23.83')->json('data.id');
    $this->b->post("/api/v1/resources/{$uuid}/labor-costs", ['effective_date' => '2099-01-01', 'base_salary' => '9000', 'charges_percent' => '0', 'admin_percent' => '0', 'monthly_hours' => '100'])
        ->assertCreated()->assertJsonPath('resource.price', '23.83'); // futura: não vale ainda
    $this->b->post("/api/v1/resources/{$uuid}/labor-costs", ['effective_date' => '2026-06-01', 'base_salary' => '1', 'charges_percent' => '0', 'admin_percent' => '0', 'monthly_hours' => '1'])
        ->assertUnprocessable()->assertJsonValidationErrors('effective_date', 'error.details');

    $this->b->delete("/api/v1/resources/{$uuid}/labor-costs/{$id2}")->assertNoContent();
    $this->b->get("/api/v1/resources/{$uuid}")->assertOk()->assertJsonPath('data.price', '18.00')->assertJsonCount(2, 'data.labor_costs');

    $mat = $this->b->post('/api/v1/resources', ['kind' => 'material', 'name' => 'DEMO', 'unit_id' => $this->un])->json('data.uuid');
    $this->b->post("/api/v1/resources/{$mat}/labor-costs", ['effective_date' => '2026-01-01', 'base_salary' => '1', 'charges_percent' => '0', 'admin_percent' => '0', 'monthly_hours' => '1'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');
});

function planilhaMateriais(array $rows): UploadedFile
{
    $path = XlsxFixture::write([['A' => 'Subgrupo', 'B' => 'Grupo *', 'C' => 'Nome *', 'D' => 'Marca', 'E' => 'Fornecedor principal', 'F' => 'Unidade de medida *'], ...$rows]);

    return new UploadedFile($path, 'product.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

it('importação: pré-visualização não grava; execução cria só as linhas válidas, com grupos e unidades, e audita', function () {
    $this->b->post('/api/v1/suppliers', ['company_uuid' => $this->b->post('/api/v1/companies', ['legal_name' => 'DEMO', 'document_number' => '11222333000181'])->json('data.uuid'),
        'type' => 'company', 'document_number' => '11222333000181', 'legal_name' => 'DEMO Engemix'])->assertCreated();
    $this->b->post('/api/v1/resources', ['kind' => 'material', 'name' => 'DEMO Cimento CP-II', 'unit_id' => $this->un])->assertCreated();

    $rows = [
        ['A' => 'Areia', 'B' => 'Agregados', 'C' => 'DEMO Areia lavada', 'D' => 'Marca X', 'E' => 'DEMO Engemix', 'F' => 'm³'],
        ['A' => '', 'B' => 'Agregados', 'C' => 'DEMO Brita 1', 'F' => 'saco'],                     // unidade nova
        ['B' => 'Cimentos', 'C' => 'DEMO Cimento CP-II', 'F' => 'm³'],                            // já existe → ignorado
        ['B' => '', 'C' => 'DEMO sem grupo', 'F' => 'm³'],                                        // erro
        ['B' => 'Agregados', 'C' => 'DEMO Pedrisco', 'E' => 'Fornecedor inexistente', 'F' => 'm³'], // aviso
    ];
    $antes = Resource::query()->withoutGlobalScopes()->count();
    $prev = $this->b->request('POST', '/api/v1/resources/import/preview', ['kind' => 'material', 'file' => planilhaMateriais($rows)])->assertOk()->json('data');

    expect($prev['summary'])->toBe(['total' => 5, 'ok' => 3, 'skip' => 1, 'error' => 1])
        ->and(collect($prev['rows'])->pluck('status')->all())->toBe(['ok', 'ok', 'skip', 'error', 'ok'])
        ->and($prev['rows'][1]['actions'])->toContain('Criar unidade "saco"')
        ->and($prev['rows'][0]['actions'])->toContain('Criar grupo "Agregados"', 'Criar subgrupo "Areia"')
        ->and($prev['rows'][4]['warnings'][0])->toContain('não cadastrado')
        ->and(Resource::query()->withoutGlobalScopes()->count())->toBe($antes); // nada gravado

    $res = $this->b->request('POST', '/api/v1/resources/import', ['kind' => 'material', 'file' => planilhaMateriais($rows)])->assertCreated()->json('data');
    expect($res['created'])->toBe(3);

    $areia = $this->b->get('/api/v1/resources?q=Areia lavada')->json('data.0');
    expect($areia['group']['name'])->toBe('Areia')->and($areia['main_supplier']['legal_name'])->toBe('DEMO Engemix')->and($areia['brand'])->toBe('Marca X');
    $this->b->get('/api/v1/units')->assertOk()->assertJsonFragment(['code' => 'saco']);
    $this->b->get('/api/v1/catalog-groups?kind=material')->assertOk()->assertJsonCount(2, 'data'); // Agregados + Areia (Cimentos não: linha ignorada)

    expect(AuditLog::query()->where('event', AuditEvent::RESOURCE_IMPORTED)->value('metadata'))->toMatchArray(['kind' => 'material', 'created' => 3, 'file' => 'product.xlsx']);
});

it('importação de equipamentos guarda modelo e tipo/condição; arquivo fora do modelo é recusado', function () {
    $path = XlsxFixture::write([
        ['A' => 'Subgrupo', 'B' => 'Grupo *', 'C' => 'Nome *', 'D' => 'Código', 'E' => 'Marca', 'F' => 'Modelo', 'G' => 'Tipo', 'H' => 'Condição', 'I' => 'Unidade de medida', 'J' => 'Fornecedor principal'],
        ['B' => 'Terraplenagem', 'C' => 'DEMO Retroescavadeira', 'D' => 'EQ-77', 'E' => 'DEMO', 'F' => 'RX-1', 'G' => 'Aterro', 'H' => 'Alugado', 'I' => 'h'],
    ]);
    $this->b->request('POST', '/api/v1/resources/import', ['kind' => 'equipment', 'file' => new UploadedFile($path, 'equipment.xlsx', null, null, true)])->assertCreated()->assertJsonPath('data.created', 1);
    $eq = $this->b->get('/api/v1/resources?kind=equipment')->json('data.0');
    expect($eq)->toMatchArray(['code' => 'EQ-77', 'model' => 'RX-1', 'notes' => 'Tipo: Aterro · Condição: Alugado']);

    $ruim = XlsxFixture::write([['A' => 'Coluna errada'], ['A' => 'x']]);
    $this->b->request('POST', '/api/v1/resources/import/preview', ['kind' => 'material', 'file' => new UploadedFile($ruim, 'x.xlsx', null, null, true)])
        ->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');
});

it('orçamento: item do catálogo copia preço vigente (origem catalog); mudar o catálogo não altera o orçamento', function () {
    $co = $this->b->post('/api/v1/companies', ['legal_name' => 'DEMO', 'document_number' => '11222333000181'])->json('data.uuid');
    $orc = $this->b->post('/api/v1/budgets', ['company_uuid' => $co, 'title' => 'DEMO', 'mode' => 'manual'])->json('data.uuid');
    $g = $this->b->post("/api/v1/budgets/{$orc}/versions/1/groups", ['code' => '1', 'name' => 'DEMO'])->json('data.id');
    $res = $this->b->post('/api/v1/resources', ['kind' => 'material', 'code' => 'MAT-9', 'name' => 'DEMO Areia', 'unit_id' => $this->un, 'price' => '50.00'])->json('data.uuid');
    $semPreco = $this->b->post('/api/v1/resources', ['kind' => 'service', 'name' => 'DEMO Frete', 'unit_id' => $this->un])->json('data.uuid');

    $this->b->post("/api/v1/budgets/{$orc}/versions/1/items/catalog", ['resource_uuid' => $res, 'group_id' => $g, 'quantity' => '2'])->assertCreated()
        ->assertJsonPath('data.source', 'catalog')->assertJsonPath('data.unit_cost', '50.00')->assertJsonPath('data.total_cost', '100.00')
        ->assertJsonPath('data.source_ref.uuid', $res)->assertJsonPath('data.unit_code', 'm³');
    $this->b->post("/api/v1/budgets/{$orc}/versions/1/items/catalog", ['resource_uuid' => $semPreco, 'group_id' => $g, 'quantity' => '1'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'domain_rule');

    $this->b->put("/api/v1/resources/{$res}", ['price' => '80.00'])->assertOk();
    $this->b->get("/api/v1/budgets/{$orc}/versions/1")->assertOk()->assertJsonPath('data.items.0.unit_cost', '50.00')->assertJsonPath('data.totals.direct_cost', '100.00');
    $this->b->get("/api/v1/budgets/{$orc}/versions/1/abc?type=resources")->assertOk()->assertJsonPath('data.0.code', 'MAT-9')->assertJsonPath('data.0.kind', 'material');
});

it('permissões e isolamento: member consulta mas não grava; outro tenant → 404', function () {
    $uuid = $this->b->post('/api/v1/resources', ['kind' => 'material', 'name' => 'DEMO', 'unit_id' => $this->un])->json('data.uuid');
    $m = new Browser($this);
    $m->login(Scenario::member($this->A, 'member')->email)->assertOk();
    $m->get('/api/v1/resources')->assertOk()->assertJsonPath('meta.total', 1);
    $m->post('/api/v1/resources', ['kind' => 'material', 'name' => 'x', 'unit_id' => $this->un])->assertForbidden();
    $m->post('/api/v1/catalog-groups', ['kind' => 'material', 'name' => 'x'])->assertForbidden();

    $bb = new Browser($this);
    $bb->login(Scenario::member(Scenario::tenant(), 'admin')->email)->assertOk();
    $bb->get("/api/v1/resources/{$uuid}")->assertNotFound();
    $bb->get('/api/v1/resources')->assertOk()->assertJsonPath('meta.total', 0);
});

it('banco: grupo precisa ser do mesmo tipo; cargo só em mão de obra; equipamento só em equipamento; preço ≥ 0', function () {
    $t = Fx::tenant();
    $unit = DB::table('units_of_measure')->insertGetId(['tenant_id' => $t, 'code' => 'un', 'name' => 'un']);
    $gMat = Fx::group($t, 'material');
    $job = Fx::jobTitle($t);
    $ins = fn (array $x) => DB::table('resources')->insert(['tenant_id' => $t, 'uuid' => (string) Str::uuid(), 'kind' => 'material', 'name' => 'x', 'unit_id' => $unit, ...$x]);

    expect(Fx::errno(fn () => $ins(['group_id' => $gMat])))->toBeNull()
        ->and(Fx::errno(fn () => $ins(['kind' => 'labor', 'group_id' => $gMat])))->toBe(1452)
        ->and(Fx::errno(fn () => $ins(['job_title_id' => $job])))->toBe(3819)
        ->and(Fx::errno(fn () => $ins(['kind' => 'labor', 'job_title_id' => $job])))->toBeNull()
        ->and(Fx::errno(fn () => $ins(['price' => -1])))->toBe(3819)
        ->and(Fx::errno(fn () => $ins(['kind' => 'tool'])))->toBe(3819)
        ->and(app(TenantContext::class)->run(Tenant::query()->find($t), fn () => Resource::query()->count()))->toBe(2); // só as 2 inserções válidas
});

it('copia insumos da base de referência para o catálogo: cópia editável, idempotente, só no tenant indicado', function () {
    $src = DB::table('reference_sources')->insertGetId(['code' => 'SINAPI', 'name' => 'SINAPI']);
    $ds = DB::table('reference_datasets')->insertGetId(['source_id' => $src, 'version' => '2024-12', 'state' => 'MT', 'location_name' => 'CUIABA', 'charges_regime' => 'without_relief', 'status' => 'active']);
    $un = DB::table('reference_units')->insertGetId(['code' => 'UN']);
    $h = DB::table('reference_units')->insertGetId(['code' => 'H']);
    $ref = function (string $code, string $desc, string $kind, int $unit, string $price) use ($ds) {
        $id = DB::table('reference_resources')->insertGetId(['dataset_id' => $ds, 'code' => $code, 'description' => $desc, 'unit_id' => $unit, 'kind' => $kind]);
        DB::table('reference_resource_prices')->insert(['resource_id' => $id, 'price' => $price]);
    };
    $ref('00001', 'DEMO TIJOLO CERAMICO', 'material', $un, '1.25');
    $ref('00002', 'DEMO PEDREIRO COM ENCARGOS', 'labor', $h, '23.83');
    $ref('00003', 'DEMO BETONEIRA', 'equipment', $un, '9.00');
    $ref('00004', str_repeat('DEMO DESCRICAO LONGA ', 12), 'material', $un, '2.00');
    $B = Scenario::tenant();
    $args = ['--tenant' => $this->A->id, '--user' => $this->admin->id, '--dataset' => $ds, '--kinds' => 'material,labor'];

    $this->artisan('obraflow:catalog:copy-references', $args + ['--dry-run' => true])->assertSuccessful();
    expect(DB::table('resources')->count())->toBe(0);

    $this->artisan('obraflow:catalog:copy-references', $args)->assertSuccessful();
    $rows = DB::table('resources')->where('tenant_id', $this->A->id)->orderBy('code')->get();
    expect($rows->pluck('code')->all())->toBe(['00001', '00002', '00004']) // equipamento não pedido
        ->and($rows[0]->kind)->toBe('material')->and($rows[0]->price)->toBe('1.25')->and($rows[0]->price_date)->toBe('2024-12-01')
        ->and($rows[1]->kind)->toBe('labor')->and($rows[1]->notes)->toContain('não desonerado, código 00002')
        ->and(mb_strlen($rows[2]->name))->toBeLessThanOrEqual(200)->and($rows[2]->notes)->toContain('Descrição completa:')
        ->and(DB::table('resources')->where('tenant_id', $B->id)->count())->toBe(0);
    // unidade já existente é reaproveitada sem diferenciar caixa ('h' = H); a ausente (UN) é criada no tenant
    expect($rows[1]->unit_id)->toBe($this->h)
        ->and(DB::table('units_of_measure')->where('tenant_id', $this->A->id)->where('code', 'UN')->value('name'))->toBe('Unidade');
    expect(AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::RESOURCE_IMPORTED)->count())->toBe(1);

    // cópia editável pela API e idempotência
    $uuid = $rows[0]->uuid;
    $this->b->put("/api/v1/resources/{$uuid}", ['price' => '1.40'])->assertOk()->assertJsonPath('data.price', '1.40');
    $this->artisan('obraflow:catalog:copy-references', $args)->assertSuccessful();
    expect(DB::table('resources')->count())->toBe(3)
        ->and(DB::table('resources')->where('uuid', $uuid)->value('price'))->toBe('1.40');

    // usuário de fora do tenant é recusado
    $this->artisan('obraflow:catalog:copy-references', ['--user' => Scenario::member($B)->id] + $args)->assertFailed();
});
