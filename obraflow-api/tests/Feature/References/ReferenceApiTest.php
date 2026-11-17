<?php

use App\Models\ReferenceDataset;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;
use Tests\Support\SinapiFixture;

/* Fase 4A — API de consulta das referências (globais, somente leitura; D-P29). */

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
    Artisan::call('obraflow:references:import', ['--competency' => '2024-12', '--state' => 'MT', '--location' => 'CUIABA', '--regime' => 'with_relief',
        '--resources' => SinapiFixture::insumos(), '--compositions' => SinapiFixture::analitico(), '--families' => SinapiFixture::familias(), '--activate' => true]);
    $this->ds = ReferenceDataset::query()->sole()->id;
    $this->b = new Browser($this);
    $this->b->login(Scenario::member($this->A, 'member')->email)->assertOk();
});

it('lista as bases ativas com fonte, competência, UF, regime e contagens', function () {
    $this->b->get('/api/v1/references/datasets')->assertOk()
        ->assertJsonPath('data.0.source.code', 'SINAPI')
        ->assertJsonPath('data.0.competency', '2024-12')
        ->assertJsonPath('data.0.state', 'MT')
        ->assertJsonPath('data.0.charges_regime', 'with_relief')
        ->assertJsonPath('data.0.resources_count', 4)
        ->assertJsonPath('data.0.compositions_count', 2);
    $this->b->get('/api/v1/references/datasets?charges_regime=without_relief')->assertOk()->assertJsonCount(0, 'data');
});

it('busca insumos por código (prefixo) ou palavras da descrição, com preço e tipo; filtra por tipo', function () {
    $this->b->get("/api/v1/references/datasets/{$this->ds}/resources?q=1127")->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.price', '2.66')->assertJsonPath('data.0.price_origin', 'CR')->assertJsonPath('data.0.kind', 'material');
    $this->b->get("/api/v1/references/datasets/{$this->ds}/resources?q=pasta lubrificante")->assertOk()->assertJsonPath('data.0.code', '20078');
    $this->b->get("/api/v1/references/datasets/{$this->ds}/resources?kind=labor")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', '4750');
    $this->b->get("/api/v1/references/datasets/{$this->ds}/resources?kind=none")->assertOk()->assertJsonPath('data.0.code', '20078');
    $this->b->get("/api/v1/references/datasets/{$this->ds}/resources?q=%25")->assertOk()->assertJsonPath('meta.total', 0); // curinga escapado
    $this->b->get("/api/v1/references/datasets/{$this->ds}/resources/99999")->assertOk()->assertJsonPath('data.price', null);
});

it('detalha a composição com itens (insumo ou composição filha), coeficientes e custos publicados', function () {
    $this->b->get("/api/v1/references/datasets/{$this->ds}/compositions?q=ferro fundido")->assertOk()->assertJsonPath('data.0.code', '97141');
    $res = $this->b->get("/api/v1/references/datasets/{$this->ds}/compositions/97141")->assertOk()
        ->assertJsonPath('data.total_cost', '3.84')
        ->assertJsonPath('data.costs.labor', '1.49')
        ->assertJsonPath('data.class.code', 'ASTU')
        ->assertJsonPath('data.dataset.competency', '2024-12')
        ->assertJsonCount(3, 'data.items');

    expect($res->json('data.items.0'))->toMatchArray(['type' => 'resource', 'code' => '20078', 'coefficient' => '0.0046000', 'unit_price' => '31.75', 'cost' => '0.14'])
        ->and($res->json('data.items.2'))->toMatchArray(['type' => 'composition', 'code' => '88316', 'unit' => 'H', 'coefficient' => '0.0320000']);
});

it('base em importação ou com falha não é visível (404); código inexistente → 404', function () {
    DB::table('reference_datasets')->where('id', $this->ds)->update(['status' => 'importing']);
    $this->b->get('/api/v1/references/datasets')->assertOk()->assertJsonCount(0, 'data');
    $this->b->get("/api/v1/references/datasets/{$this->ds}/compositions")->assertNotFound();

    DB::table('reference_datasets')->where('id', $this->ds)->update(['status' => 'active']);
    $this->b->get("/api/v1/references/datasets/{$this->ds}/compositions/00000")->assertNotFound();
    $this->b->get('/api/v1/references/datasets/999999/resources')->assertNotFound();
});

it('base substituída só aparece quando pedida (include_superseded) e continua consultável', function () {
    DB::table('reference_datasets')->where('id', $this->ds)->update(['status' => 'superseded']);
    $this->b->get('/api/v1/references/datasets')->assertOk()->assertJsonCount(0, 'data');
    $this->b->get('/api/v1/references/datasets?include_superseded=1')->assertOk()->assertJsonPath('data.0.status', 'superseded');
    $this->b->get("/api/v1/references/datasets/{$this->ds}/compositions/97141")->assertOk(); // orçamentos antigos seguem rastreáveis
});

it('exige tenant ativo e references.view; viewer não consulta; dados iguais para qualquer tenant', function () {
    $v = new Browser($this);
    $v->login(Scenario::member($this->A, 'viewer')->email)->assertOk();
    $v->get('/api/v1/references/datasets')->assertForbidden();

    $outro = new Browser($this);
    $outro->login(Scenario::member($this->B, 'member')->email)->assertOk();
    $outro->get("/api/v1/references/datasets/{$this->ds}/compositions/97141")->assertOk()->assertJsonPath('data.total_cost', '3.84');

    $this->getJson('/api/v1/references/datasets')->assertUnauthorized();
});

it('não há rota de escrita: POST/PUT/DELETE não existem (escrita só pelo comando)', function () {
    $this->b->post('/api/v1/references/datasets', [])->assertStatus(405);
    $this->b->put("/api/v1/references/datasets/{$this->ds}/compositions/97141", [])->assertStatus(405);
    $this->b->delete("/api/v1/references/datasets/{$this->ds}/resources/11270")->assertStatus(405);
});

it('token com references.view consulta (D-29)', function () {
    $admin = new Browser($this);
    $admin->login(Scenario::member($this->A, 'admin')->email)->assertOk();
    $token = $admin->post('/api/v1/tokens', ['name' => 'orc', 'expires_in_days' => 30, 'abilities' => ['references.view']])->assertCreated()->json('token');

    $this->withToken($token)->getJson("/api/v1/references/datasets/{$this->ds}/resources?q=1127")->assertOk()->assertJsonPath('meta.total', 1);
    $this->withToken($token)->getJson('/api/v1/projects')->assertForbidden();
});
