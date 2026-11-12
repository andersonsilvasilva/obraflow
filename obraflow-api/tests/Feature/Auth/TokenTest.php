<?php

use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\TenantSetting;
use App\Modules\Audit\AuditEvent;
use App\Modules\Auth\Services\IssueApiToken;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
    $this->adminA = Scenario::member($this->A, 'admin');
    $this->memberA = Scenario::member($this->A, 'member');
    $this->adminB = Scenario::member($this->B, 'admin');
});

/** Emite um token de API pelo SPA (como um administrador faria) e devolve o valor em claro. */
function issueToken(object $test, string $email, int $days = 30, string $name = 'integração ERP'): string
{
    $browser = new Browser($test);
    $browser->login($email)->assertOk();

    return $browser->post('/api/v1/tokens', ['name' => $name, 'expires_in_days' => $days, 'abilities' => Scenario::TOKEN_ABILITIES])->assertCreated()->json('token');
}

// ---------------------------------------------------------------- emissão
it('emite token FIXADO no tenant do emissor, exibe o valor uma só vez e audita sem vazá-lo', function () {
    $browser = new Browser($this);
    $browser->login($this->adminA->email)->assertOk();

    $res = $browser->post('/api/v1/tokens', ['name' => 'ERP', 'expires_in_days' => 30, 'abilities' => Scenario::TOKEN_ABILITIES])->assertCreated();
    $plain = $res->json('token');

    expect($plain)->toBeString()->toContain('|');

    $row = PersonalAccessToken::query()->findOrFail($res->json('data.id'));
    expect($row->tenant_id)->toBe($this->A->id)
        ->and($row->tokenable_id)->toBe($this->adminA->id)
        ->and((int) $row->expires_at->diffInDays(now(), true))->toBeGreaterThanOrEqual(29)
        ->and($row->token)->not->toBe($plain); // guardado como hash

    // listagem nunca devolve o valor
    $list = $browser->get('/api/v1/tokens')->assertOk();
    expect($list->getContent())->not->toContain($plain)->not->toContain($row->token);

    $log = AuditLog::query()->where('event', AuditEvent::TOKEN_CREATED)->firstOrFail();
    expect($log->tenant_id)->toBe($this->A->id)->and(json_encode($log->toArray()))->not->toContain($plain);
});

it('a expiração é obrigatória e limitada a 90 dias', function () {
    $browser = new Browser($this);
    $browser->login($this->adminA->email)->assertOk();

    foreach ([[], ['expires_in_days' => 0, 'abilities' => Scenario::TOKEN_ABILITIES], ['expires_in_days' => 91, 'abilities' => Scenario::TOKEN_ABILITIES], ['expires_in_days' => 'abc'], ['expires_in_days' => null]] as $bad) {
        $browser->post('/api/v1/tokens', ['name' => 'x'] + $bad)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    $browser->post('/api/v1/tokens', ['name' => 'ok', 'expires_in_days' => 90, 'abilities' => Scenario::TOKEN_ABILITIES])->assertCreated();
    expect(PersonalAccessToken::query()->count())->toBe(1);
});

it('o serviço também recusa validade fora do limite (defesa em profundidade)', function () {
    $service = app(IssueApiToken::class);

    expect(fn () => $service->issue($this->adminA, $this->A, 'x', 91, ['tenant.view']))->toThrow(ApiException::class)
        ->and(fn () => $service->issue($this->adminA, $this->A, 'x', 0, ['tenant.view']))->toThrow(ApiException::class)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('quem não tem tokens.manage não emite, lista nem revoga', function () {
    $browser = new Browser($this);
    $browser->login($this->memberA->email)->assertOk();

    $browser->post('/api/v1/tokens', ['name' => 'x', 'expires_in_days' => 10, 'abilities' => Scenario::TOKEN_ABILITIES])->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    $browser->get('/api/v1/tokens')->assertForbidden();
    $browser->delete('/api/v1/tokens/1')->assertForbidden();
});

// Camadas: (1) o PrivilegeGuard recusa primeiro — sem vínculo não há permissão no tenant; (2) se algo burlasse,
// a FK composta recusaria (ver TenantIntegrityTest e TenantIsolationTest "token com tenant_id de outro tenant").
it('é impossível emitir token para um tenant onde o usuário não tem vínculo', function () {
    expect(fn () => app(IssueApiToken::class)->issue($this->adminA, $this->B, 'x', 10, ['tenant.view']))
        ->toThrow(ApiException::class);

    expect(PersonalAccessToken::query()->count())->toBe(0); // a transação foi revertida
});

// ---------------------------------------------------------------- uso do token (#8, #9, #16)
it('token vinculado ao tenant acessa SOMENTE o tenant correto', function () {
    $plain = issueToken($this, $this->adminA->email);

    $this->withToken($plain)->getJson('/api/v1/tenant')->assertOk()
        ->assertJsonPath('data.uuid', $this->A->uuid)->assertJsonPath('data.slug', $this->A->slug);
});

it('token de A não enxerga dados de B', function () {
    app(TenantContext::class)->run($this->B, fn () => TenantSetting::create(['setting_key' => 'segredo-b', 'setting_value' => ['value' => 1]]));
    $plain = issueToken($this, $this->adminA->email);

    $res = $this->withToken($plain)->getJson('/api/v1/tenant/settings')->assertOk();

    expect($res->getContent())->not->toContain('segredo-b');
});

it('token SEM tenant não acessa nenhum dado de negócio, mas acessa o grupo auth/*', function () {
    $semTenant = $this->adminA->createToken('sem-tenant')->plainTextToken;

    foreach (['/api/v1/tenant', '/api/v1/tenant/settings', '/api/v1/tokens', '/api/v1/audit-logs'] as $uri) {
        $this->withToken($semTenant)->getJson($uri)->assertForbidden()->assertJsonPath('error.code', 'tenant_required');
    }
    $this->withToken($semTenant)->putJson('/api/v1/tenant/settings/x', ['value' => 1])->assertForbidden()->assertJsonPath('error.code', 'tenant_required');

    $this->withToken($semTenant)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('active_tenant', null);
    $this->withToken($semTenant)->getJson('/api/v1/auth/tenants')->assertOk();
});

it('token é fixo: não consegue trocar de tenant', function () {
    $plain = issueToken($this, $this->adminA->email);

    $this->withToken($plain)->postJson('/api/v1/session/tenant', ['tenant_uuid' => $this->A->uuid])
        ->assertForbidden()->assertJsonPath('error.code', 'tenant_fixed');
});

it('ignora tenant forjado (header/corpo/query) no uso do token', function () {
    $plain = issueToken($this, $this->adminA->email);

    $res = $this->withToken($plain)
        ->withHeaders(['X-Tenant' => $this->B->uuid])
        ->putJson('/api/v1/tenant/settings/forjado?tenant_id='.$this->B->id, ['value' => 1, 'tenant_id' => $this->B->id, 'tenant_uuid' => $this->B->uuid])
        ->assertOk();

    expect(TenantSetting::query()->withoutGlobalScopes()->where('setting_key', 'forjado')->pluck('tenant_id')->all())->toBe([$this->A->id]);
});

// ---------------------------------------------------------------- expiração e revogação (#10)
it('token expirado (expires_at) deixa de funcionar e a negação é auditada', function () {
    $plain = issueToken($this, $this->adminA->email, days: 5);
    $this->withToken($plain)->getJson('/api/v1/tenant')->assertOk();

    $this->travel(6)->days();

    $this->withToken($plain)->getJson('/api/v1/tenant')->assertUnauthorized();
    expect(AuditLog::query()->where('event', AuditEvent::TOKEN_EXPIRED_USE_DENIED)->exists())->toBeTrue();
});

it('teto de segurança: mesmo sem expires_at nenhum token vale mais que 90 dias desde a criação', function () {
    $legacy = $this->adminA->createToken('legado', ['tenant.view']);
    $legacy->accessToken->forceFill(['tenant_id' => $this->A->id])->save(); // sem expires_at

    $this->withToken($legacy->plainTextToken)->getJson('/api/v1/tenant')->assertOk();

    $this->travel(91)->days();

    $this->withToken($legacy->plainTextToken)->getJson('/api/v1/tenant')->assertUnauthorized();
});

it('revogar o token o inutiliza imediatamente e audita sem expor o valor', function () {
    $browser = new Browser($this);
    $browser->login($this->adminA->email)->assertOk();
    $created = $browser->post('/api/v1/tokens', ['name' => 'rev', 'expires_in_days' => 10, 'abilities' => Scenario::TOKEN_ABILITIES])->assertCreated();
    $plain = $created->json('token');

    $this->withToken($plain)->getJson('/api/v1/tenant')->assertOk();

    $browser->delete('/api/v1/tokens/'.$created->json('data.id'))->assertNoContent();

    $this->withToken($plain)->getJson('/api/v1/tenant')->assertUnauthorized();
    expect(AuditLog::query()->where('event', AuditEvent::TOKEN_REVOKED)->count())->toBe(1)
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain($plain);
});

it('admin de outro tenant não vê nem revoga token de A (404)', function () {
    $browserA = new Browser($this);
    $browserA->login($this->adminA->email)->assertOk();
    $id = $browserA->post('/api/v1/tokens', ['name' => 'a', 'expires_in_days' => 10, 'abilities' => Scenario::TOKEN_ABILITIES])->assertCreated()->json('data.id');

    $browserB = new Browser($this);
    $browserB->login($this->adminB->email)->assertOk();

    $browserB->delete('/api/v1/tokens/'.$id)->assertNotFound();
    expect($browserB->get('/api/v1/tokens')->assertOk()->json('data'))->toBeEmpty();
    expect(PersonalAccessToken::query()->find($id))->not->toBeNull();
});

it('logout com token revoga o token usado', function () {
    $plain = issueToken($this, $this->adminA->email);

    $this->withToken($plain)->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->withToken($plain)->getJson('/api/v1/tenant')->assertUnauthorized();
});

it('funciona igual com sessão e com token (mesmo usuário, mesmas permissões)', function () {
    $browser = new Browser($this);
    $browser->login($this->adminA->email)->assertOk();
    $viaSessao = $browser->get('/api/v1/tenant')->assertOk()->json('data');

    $plain = $browser->post('/api/v1/tokens', ['name' => 'p', 'expires_in_days' => 5, 'abilities' => Scenario::TOKEN_ABILITIES])->json('token');
    $viaToken = $this->withToken($plain)->getJson('/api/v1/tenant')->assertOk()->json('data');

    expect($viaToken)->toBe($viaSessao);
    $this->withToken($plain)->putJson('/api/v1/tenant/settings/moeda', ['value' => 'BRL'])->assertOk();
    $browser->put('/api/v1/tenant/settings/fuso', ['value' => 'UTC'])->assertOk();
});
