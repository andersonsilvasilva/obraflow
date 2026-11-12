<?php

use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantUser;
use App\Modules\Audit\AuditEvent;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Browser;
use Tests\Support\Scenario;

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
    $this->browser = new Browser($this);
});

function selectTenant(Browser $b, Tenant $t)
{
    return $b->post('/api/v1/session/tenant', ['tenant_uuid' => $t->uuid]);
}

function settingKeys(object $res): array
{
    return collect($res->json('data'))->pluck('key')->sort()->values()->all();
}

// ---------------------------------------------------------------- #1 usuário global em vários tenants / #11 troca de contexto
it('usuário global vê só o tenant ativo e as permissões mudam ao trocar de tenant', function () {
    $user = Scenario::member($this->A, 'admin');
    Scenario::attach($this->B, $user, 'viewer');

    $this->browser->login($user->email)->assertOk();

    selectTenant($this->browser, $this->A)->assertOk()->assertJsonPath('active_tenant.uuid', $this->A->uuid);
    $this->browser->put('/api/v1/tenant/settings/moeda', ['value' => 'BRL'])->assertOk();
    $this->browser->get('/api/v1/tenant')->assertOk()->assertJsonPath('data.roles', ['admin']);

    selectTenant($this->browser, $this->B)->assertOk()->assertJsonPath('active_tenant.uuid', $this->B->uuid);

    // no tenant B: outro papel (viewer), outro conjunto de dados, sem a permissão de escrever
    $this->browser->get('/api/v1/tenant')->assertOk()->assertJsonPath('data.slug', $this->B->slug)->assertJsonPath('data.roles', ['viewer']);
    $this->browser->get('/api/v1/tenant/settings')->assertForbidden(); // viewer não tem settings.view
    $this->browser->put('/api/v1/tenant/settings/moeda', ['value' => 'USD'])->assertForbidden();

    // volta para A: tudo como estava
    selectTenant($this->browser, $this->A)->assertOk();
    $this->browser->get('/api/v1/tenant')->assertOk()->assertJsonPath('data.roles', ['admin']);
    expect(settingKeys($this->browser->get('/api/v1/tenant/settings')->assertOk()))->toBe(['moeda']);
});

it('o /auth/me lista as permissões do tenant ATIVO (e muda ao trocar)', function () {
    $user = Scenario::member($this->A, 'admin');
    Scenario::attach($this->B, $user, 'viewer');
    $this->browser->login($user->email)->assertOk();

    selectTenant($this->browser, $this->A)->assertOk();
    expect($this->browser->get('/api/v1/auth/me')->json('permissions'))->toContain('settings.manage', 'tokens.manage');

    selectTenant($this->browser, $this->B)->assertOk();
    expect($this->browser->get('/api/v1/auth/me')->json('permissions'))->toBe(['tenant.view']);
});

// ---------------------------------------------------------------- #2 isolamento
it('um tenant não enxerga nem altera dados do outro, e tenant forjado é ignorado', function () {
    $adminA = Scenario::member($this->A, 'admin');
    $adminB = Scenario::member($this->B, 'admin');

    $b = new Browser($this);
    $b->login($adminB->email)->assertOk();
    $b->put('/api/v1/tenant/settings/segredo-b', ['value' => 'dado confidencial de B'])->assertOk();
    $b->put('/api/v1/tenant/settings/compartilhada', ['value' => 'valor-B'])->assertOk();

    $this->browser->login($adminA->email)->assertOk();
    $lista = $this->browser->get('/api/v1/tenant/settings')->assertOk();

    expect($lista->getContent())->not->toContain('segredo-b')->not->toContain('dado confidencial');

    // escrita com tenant forjado em header, query e corpo: vai para A, nunca para B
    $this->browser->put(
        '/api/v1/tenant/settings/compartilhada?tenant_id='.$this->B->id,
        ['value' => 'valor-A', 'tenant_id' => $this->B->id, 'tenant_uuid' => $this->B->uuid],
        ['X-Tenant' => $this->B->uuid],
    )->assertOk();

    $rows = TenantSetting::query()->withoutGlobalScopes()->where('setting_key', 'compartilhada')->orderBy('tenant_id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('tenant_id', $this->A->id)->setting_value['value'])->toBe('valor-A')
        ->and($rows->firstWhere('tenant_id', $this->B->id)->setting_value['value'])->toBe('valor-B'); // B intacto
});

it('sem tenant selecionado, dados de negócio são recusados', function () {
    $user = Scenario::member($this->A, 'admin');
    Scenario::attach($this->B, $user, 'admin');
    $this->browser->login($user->email)->assertOk()->assertJsonPath('active_tenant', null);

    foreach (['/api/v1/tenant', '/api/v1/tenant/settings', '/api/v1/audit-logs'] as $uri) {
        $this->browser->get($uri)->assertForbidden()->assertJsonPath('error.code', 'tenant_required');
    }
});

// ---------------------------------------------------------------- seleção de tenant
it('não seleciona tenant sem vínculo; "não existe" e "sem vínculo" têm a MESMA resposta e são auditados', function () {
    $user = Scenario::member($this->A, 'admin');
    $this->browser->login($user->email)->assertOk();

    $semVinculo = selectTenant($this->browser, $this->B)->assertForbidden();
    $inexistente = $this->browser->post('/api/v1/session/tenant', ['tenant_uuid' => (string) Str::uuid()])->assertForbidden();

    expect($semVinculo->json('error'))->toMatchArray(['code' => 'tenant_forbidden'])
        ->and($semVinculo->json('error.code'))->toBe($inexistente->json('error.code'))
        ->and($semVinculo->json('error.message'))->toBe($inexistente->json('error.message'));

    expect(AuditLog::query()->where('event', AuditEvent::TENANT_SELECTION_DENIED)->count())->toBe(2);
    // a sessão continua no tenant que o login selecionou automaticamente
    $this->browser->get('/api/v1/auth/me')->assertJsonPath('active_tenant.uuid', $this->A->uuid);
});

it('trocar de tenant audita tenant.selected com origem e destino e regenera a sessão', function () {
    $user = Scenario::member($this->A, 'admin');
    Scenario::attach($this->B, $user, 'admin');
    $this->browser->login($user->email)->assertOk();

    selectTenant($this->browser, $this->A)->assertOk();
    $antes = $this->browser->sessionCookie();
    selectTenant($this->browser, $this->B)->assertOk();
    $depois = $this->browser->sessionCookie();

    expect($depois)->not->toBe($antes); // proteção contra fixação de sessão

    $logs = AuditLog::query()->where('event', AuditEvent::TENANT_SELECTED)->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs[0]->metadata)->toMatchArray(['from_tenant_id' => null, 'to_tenant_id' => $this->A->id])
        ->and($logs[1]->metadata)->toMatchArray(['from_tenant_id' => $this->A->id, 'to_tenant_id' => $this->B->id])
        ->and($logs[1]->user_id)->toBe($user->id)
        ->and($logs[1]->tenant_id)->toBe($this->B->id);

    expect(TenantUser::query()->where('tenant_id', $this->B->id)->where('user_id', $user->id)->first()->last_used_at)->not->toBeNull();
});

// ---------------------------------------------------------------- #5/#6/#7 bloqueios
it('vínculo suspenso ou removido bloqueia na PRÓXIMA requisição (sessão e token)', function () {
    foreach ([MembershipStatus::Suspended, MembershipStatus::Removed] as $status) {
        RateLimiter::clear('login');
        $user = Scenario::member($this->A, 'admin');

        $browser = new Browser($this);
        $browser->login($user->email)->assertOk();
        $browser->get('/api/v1/tenant')->assertOk();

        $token = $browser->post('/api/v1/tokens', ['name' => 't', 'expires_in_days' => 5])->json('token');
        $this->withToken($token)->getJson('/api/v1/tenant')->assertOk();

        TenantUser::query()->where('tenant_id', $this->A->id)->where('user_id', $user->id)->update(['membership_status' => $status->value]);

        $browser->get('/api/v1/tenant')->assertForbidden()->assertJsonPath('error.code', 'tenant_forbidden');
        $this->withToken($token)->getJson('/api/v1/tenant')->assertForbidden()->assertJsonPath('error.code', 'tenant_forbidden');
    }
});

it('vínculo suspenso não consegue selecionar o tenant', function () {
    $user = Scenario::member($this->A, 'admin');
    Scenario::attach($this->B, $user, 'admin', MembershipStatus::Suspended);
    $this->browser->login($user->email)->assertOk(); // só A está ativo ⇒ selecionado automaticamente

    selectTenant($this->browser, $this->B)->assertForbidden()->assertJsonPath('error.code', 'tenant_forbidden');
});

it('tenant suspenso ou cancelado bloqueia (seleção, sessão aberta e token)', function () {
    foreach ([TenantStatus::Suspended, TenantStatus::Canceled] as $status) {
        RateLimiter::clear('login');
        $tenant = Scenario::tenant();
        $user = Scenario::member($tenant, 'admin');
        $browser = new Browser($this);
        $browser->login($user->email)->assertOk();
        $token = $browser->post('/api/v1/tokens', ['name' => 't', 'expires_in_days' => 5])->json('token');

        $tenant->forceFill(['status' => $status])->save();

        $browser->get('/api/v1/tenant')->assertForbidden()->assertJsonPath('error.code', 'tenant_suspended');
        $this->withToken($token)->getJson('/api/v1/tenant')->assertForbidden()->assertJsonPath('error.code', 'tenant_suspended');
        selectTenant($browser, $tenant)->assertForbidden()->assertJsonPath('error.code', 'tenant_suspended');
    }
});

it('tenant removido (soft delete) é tratado como sem acesso', function () {
    $user = Scenario::member($this->A, 'admin');
    $this->browser->login($user->email)->assertOk();
    $this->browser->get('/api/v1/tenant')->assertOk();

    $this->A->delete();

    $this->browser->get('/api/v1/tenant')->assertForbidden()->assertJsonPath('error.code', 'tenant_forbidden');
});

it('usuário bloqueado perde acesso também por token', function () {
    $user = Scenario::member($this->A, 'admin');
    $browser = new Browser($this);
    $browser->login($user->email)->assertOk();
    $token = $browser->post('/api/v1/tokens', ['name' => 't', 'expires_in_days' => 5])->json('token');
    $this->withToken($token)->getJson('/api/v1/tenant')->assertOk();

    $user->forceFill(['status' => 'blocked'])->save();

    $this->withToken($token)->getJson('/api/v1/tenant')->assertForbidden()->assertJsonPath('error.code', 'account_blocked');
});

// ---------------------------------------------------------------- #12 inconsistência entre abas
it('X-Tenant-Hint divergente => 409 SEM executar a operação (aba desatualizada)', function () {
    $user = Scenario::member($this->A, 'admin');
    Scenario::attach($this->B, $user, 'admin');
    $this->browser->login($user->email)->assertOk();

    // as duas abas compartilham a MESMA sessão do navegador
    selectTenant($this->browser, $this->A)->assertOk();      // aba 1 exibe A
    selectTenant($this->browser, $this->B)->assertOk();      // aba 2 troca para B

    // aba 1 (ainda exibindo A) tenta gravar: o servidor recusa e NADA é gravado
    $this->browser->put('/api/v1/tenant/settings/da-aba-1', ['value' => 'x'], ['X-Tenant-Hint' => $this->A->uuid])
        ->assertStatus(409)->assertJsonPath('error.code', 'tenant_mismatch');
    expect(TenantSetting::query()->withoutGlobalScopes()->where('setting_key', 'da-aba-1')->count())->toBe(0);

    // a aba 2 (hint = B) funciona; sem hint também (o hint é opcional)
    $this->browser->put('/api/v1/tenant/settings/da-aba-2', ['value' => 'x'], ['X-Tenant-Hint' => $this->B->uuid])->assertOk();
    $this->browser->put('/api/v1/tenant/settings/sem-hint', ['value' => 'x'])->assertOk();

    $keys = TenantSetting::query()->withoutGlobalScopes()->whereIn('setting_key', ['da-aba-2', 'sem-hint'])->pluck('tenant_id')->unique()->all();
    expect($keys)->toBe([$this->B->id]);
});

it('o hint NÃO é autoridade: não troca o tenant nem dá acesso a tenant alheio', function () {
    $user = Scenario::member($this->A, 'admin');
    $this->browser->login($user->email)->assertOk();

    // hint de um tenant onde o usuário NÃO é membro: 409, nunca acesso
    $this->browser->get('/api/v1/tenant', ['X-Tenant-Hint' => $this->B->uuid])->assertStatus(409);
    $this->browser->get('/api/v1/tenant', ['X-Tenant-Hint' => 'lixo'])->assertStatus(409);

    // a sessão continua em A
    $this->browser->get('/api/v1/tenant')->assertOk()->assertJsonPath('data.slug', $this->A->slug);
});

it('o hint também se aplica a requisições com token', function () {
    $user = Scenario::member($this->A, 'admin');
    $b = new Browser($this);
    $b->login($user->email)->assertOk();
    $token = $b->post('/api/v1/tokens', ['name' => 't', 'expires_in_days' => 5])->json('token');

    $this->withToken($token)->withHeaders(['X-Tenant-Hint' => $this->A->uuid])->getJson('/api/v1/tenant')->assertOk();
    $this->withToken($token)->withHeaders(['X-Tenant-Hint' => $this->B->uuid])->getJson('/api/v1/tenant')->assertStatus(409);
});

// ---------------------------------------------------------------- contexto
it('o contexto do tenant é descartado ao fim da requisição (sem vazamento entre requisições)', function () {
    $user = Scenario::member($this->A, 'admin');
    $this->browser->login($user->email)->assertOk();
    $this->browser->get('/api/v1/tenant')->assertOk();

    expect(app(TenantContext::class)->has())->toBeFalse()
        ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
});

it('requisições alternadas de usuários de tenants diferentes não se contaminam', function () {
    $adminA = Scenario::member($this->A, 'admin');
    $adminB = Scenario::member($this->B, 'admin');
    $ba = new Browser($this);
    $bb = new Browser($this);
    $ba->login($adminA->email)->assertOk();
    $bb->login($adminB->email)->assertOk();

    foreach (range(1, 3) as $i) {
        $ba->put('/api/v1/tenant/settings/rodada', ['value' => "A{$i}"])->assertOk();
        $bb->put('/api/v1/tenant/settings/rodada', ['value' => "B{$i}"])->assertOk();
        $ba->get('/api/v1/tenant')->assertJsonPath('data.slug', $this->A->slug);
        $bb->get('/api/v1/tenant')->assertJsonPath('data.slug', $this->B->slug);
    }

    $valores = TenantSetting::query()->withoutGlobalScopes()->where('setting_key', 'rodada')->pluck('setting_value', 'tenant_id');
    expect($valores[$this->A->id]['value'])->toBe('A3')->and($valores[$this->B->id]['value'])->toBe('B3');
});

it('token com tenant_id de outro tenant que o usuário não integra não pode ser criado (banco)', function () {
    $user = Scenario::member($this->A, 'admin');
    $token = $user->createToken('x')->accessToken;

    expect(fn () => $token->forceFill(['tenant_id' => $this->B->id])->save())->toThrow(QueryException::class);
    expect(PersonalAccessToken::query()->whereKey($token->id)->value('tenant_id'))->toBeNull();
});
