<?php

use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Modules\Access\Services\PermissionCatalog;
use App\Modules\Audit\AuditEvent;
use App\Modules\Auth\Services\IssueApiToken;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Support\Browser;
use Tests\Support\Scenario;

/*
| D-29 — escopo dos tokens: vinculados a um tenant, menor privilégio (abilities explícitas, sem curinga),
| expiração obrigatória (≤ 90 dias), revogação individual, autorização = abilities ∩ permissões do usuário,
| e nenhum token contorna o SetTenantContext.
*/

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
    $this->adminA = Scenario::member($this->A, 'admin');
    $this->browser = new Browser($this);
    $this->browser->login($this->adminA->email)->assertOk();
});

function issueWith(Browser $b, array $abilities, int $days = 30)
{
    return $b->post('/api/v1/tokens', ['name' => 'integração', 'expires_in_days' => $days, 'abilities' => $abilities]);
}

// ---------------------------------------------------------------- emissão: menor privilégio
it('exige abilities explícitas: sem o campo, vazio ou nulo é recusado (sem padrão genérico)', function () {
    foreach ([[], ['abilities' => []], ['abilities' => null], ['abilities' => 'tenant.view']] as $bad) {
        $this->browser->post('/api/v1/tokens', ['name' => 'x', 'expires_in_days' => 10] + $bad)
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('recusa curinga e abilities inexistentes', function () {
    foreach ([['*'], ['tenant.*'], ['settings.view', '*'], ['inventada.acao'], ['tenant.view', 'tenant.view']] as $bad) {
        issueWith($this->browser, $bad)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('token NUNCA recebe permissões de gestão de acesso', function () {
    foreach (PermissionCatalog::RESTRICTED_FOR_TOKENS as $restrita) {
        issueWith($this->browser, ['tenant.view', $restrita])->assertStatus(422);
    }

    expect(PermissionCatalog::tokenAssignable())->not->toContain('tokens.manage', 'roles.manage', 'members.manage', 'members.invite')
        ->and(PermissionCatalog::tokenAssignable())->toContain('tenant.view', 'settings.manage', 'audit.view');
});

it('o serviço também recusa abilities inválidas (defesa em profundidade)', function () {
    $service = app(IssueApiToken::class);

    foreach ([[], ['*'], ['roles.manage'], ['nao.existe']] as $bad) {
        expect(fn () => $service->issue($this->adminA, $this->A, 'x', 10, $bad))
            ->toThrow(ApiException::class);
    }

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('ninguém delega o que não tem: as abilities cabem nas permissões do emissor', function () {
    // emissor com tokens.manage, mas SEM settings.manage
    $emissor = Scenario::member($this->A, 'viewer');
    app(TenantContext::class)->run($this->A, function () use ($emissor) {
        $r = Role::create(['name' => 'integrador', 'guard_name' => 'web', 'tenant_id' => $this->A->id]);
        $r->syncPermissions(['tenant.view', 'tokens.manage']);
        $emissor->assignRole($r);
    }, $emissor);

    $b = new Browser($this);
    $b->login($emissor->email)->assertOk();

    issueWith($b, ['settings.manage'])->assertForbidden()->assertJsonPath('error.code', 'privilege_escalation');
    issueWith($b, ['tenant.view'])->assertCreated();
});

it('grava exatamente as abilities pedidas, a expiração e o tenant, e audita sem vazar o valor', function () {
    $res = issueWith($this->browser, ['tenant.view', 'settings.view'], 45)->assertCreated();

    $row = PersonalAccessToken::query()->findOrFail($res->json('data.id'));
    expect($row->abilities)->toBe(['tenant.view', 'settings.view'])
        ->and($row->tenant_id)->toBe($this->A->id)
        ->and($row->expires_at)->not->toBeNull()
        ->and($res->json('data.abilities'))->toBe(['tenant.view', 'settings.view']);

    $log = AuditLog::query()->where('event', AuditEvent::TOKEN_CREATED)->firstOrFail();
    expect($log->metadata['abilities'])->toBe(['tenant.view', 'settings.view'])
        ->and(json_encode($log->toArray()))->not->toContain($res->json('token'));
});

// ---------------------------------------------------------------- uso: abilities ∩ permissões do usuário
it('o token só faz o que suas abilities permitem, ainda que o usuário (admin) possa mais', function () {
    $plain = issueWith($this->browser, ['tenant.view', 'settings.view'])->json('token');

    $this->withToken($plain)->getJson('/api/v1/tenant')->assertOk();                                   // ability presente
    $this->withToken($plain)->getJson('/api/v1/tenant/settings')->assertOk();                          // ability presente
    $this->withToken($plain)->putJson('/api/v1/tenant/settings/x', ['value' => 1])->assertForbidden(); // settings.manage ausente
    $this->withToken($plain)->getJson('/api/v1/audit-logs')->assertForbidden();                        // audit.view ausente
    $this->withToken($plain)->getJson('/api/v1/members')->assertForbidden();
    $this->withToken($plain)->getJson('/api/v1/roles')->assertForbidden();

    // a MESMA pessoa, por sessão, continua com todas as permissões do papel admin
    $this->browser->put('/api/v1/tenant/settings/x', ['value' => 1])->assertOk();
    expect(TenantSetting::query()->withoutGlobalScopes()->where('setting_key', 'x')->count())->toBe(1);
});

it('a interseção é VIVA: se o emissor perde a permissão, o token perde junto', function () {
    $segundoAdmin = Scenario::member($this->A, 'admin'); // para o admin poder deixar de ser admin
    $plain = issueWith($this->browser, ['tenant.view', 'settings.manage'])->json('token');
    $this->withToken($plain)->putJson('/api/v1/tenant/settings/a', ['value' => 1])->assertOk();

    // o emissor é rebaixado: perde o papel admin e fica só com `viewer`
    app(TenantContext::class)->run($this->A, function () {
        $u = User::query()->findOrFail($this->adminA->id);
        $u->removeRole('admin');
        $u->assignRole('viewer');
    });

    $this->withToken($plain)->putJson('/api/v1/tenant/settings/b', ['value' => 1])->assertForbidden(); // tinha a ability, não tem mais a permissão
    $this->withToken($plain)->getJson('/api/v1/tenant')->assertOk();                                      // ainda tem tenant.view nos dois lados
    expect($segundoAdmin->isActive())->toBeTrue();
});

it('wildcard em token legado NÃO vale: nenhuma permissão é concedida', function () {
    $legado = $this->adminA->createToken('legado-curinga', ['*'], now()->addDay());
    $legado->accessToken->forceFill(['tenant_id' => $this->A->id])->save();

    foreach (['/api/v1/tenant', '/api/v1/tenant/settings', '/api/v1/members', '/api/v1/roles'] as $uri) {
        $this->withToken($legado->plainTextToken)->getJson($uri)->assertForbidden();
    }
});

it('abilities de gestão de acesso gravadas por fora do fluxo também são ignoradas no uso', function () {
    $forjado = $this->adminA->createToken('forjado', ['tokens.manage', 'roles.manage', 'members.manage', 'members.invite', 'tenant.view'], now()->addDay());
    $forjado->accessToken->forceFill(['tenant_id' => $this->A->id])->save();
    $t = $forjado->plainTextToken;

    $this->withToken($t)->getJson('/api/v1/tenant')->assertOk();
    $this->withToken($t)->postJson('/api/v1/tokens', ['name' => 'filho', 'expires_in_days' => 5, 'abilities' => ['tenant.view']])->assertForbidden();
    $this->withToken($t)->postJson('/api/v1/roles', ['name' => 'x', 'permissions' => []])->assertForbidden();
    $this->withToken($t)->postJson('/api/v1/invitations', ['email' => 'a@b.test', 'role_id' => 1])->assertForbidden();
    $this->withToken($t)->postJson('/api/v1/members/1/suspend')->assertForbidden();
    expect(PersonalAccessToken::query()->count())->toBe(1);
});

it('/auth/me de um token lista só as permissões EFETIVAS (abilities ∩ usuário)', function () {
    $plain = issueWith($this->browser, ['tenant.view', 'audit.view'])->json('token');

    $permissoes = $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk()->json('permissions');

    expect($permissoes)->toBe(['audit.view', 'tenant.view']);
    // a sessão do mesmo usuário continua enxergando todas as do papel
    expect($this->browser->get('/api/v1/auth/me')->json('permissions'))->toContain('tokens.manage', 'roles.manage');
});

// ---------------------------------------------------------------- vínculo ao tenant e sem tenant
it('um token não opera em mais de um tenant, nem troca de tenant', function () {
    Scenario::attach($this->B, $this->adminA, 'admin');
    app(TenantContext::class)->run($this->B, fn () => TenantSetting::create(['setting_key' => 'so-do-b', 'setting_value' => ['value' => 1]]));

    $plain = issueWith($this->browser, ['tenant.view', 'settings.view'])->json('token'); // emitido com o tenant A ativo

    $res = $this->withToken($plain)->getJson('/api/v1/tenant/settings')->assertOk();
    expect($res->getContent())->not->toContain('so-do-b');

    $this->withToken($plain)->postJson('/api/v1/session/tenant', ['tenant_uuid' => $this->B->uuid])->assertForbidden()->assertJsonPath('error.code', 'tenant_fixed');
    $this->withToken($plain)->withHeaders(['X-Tenant' => $this->B->uuid])->getJson('/api/v1/tenant')->assertOk()->assertJsonPath('data.slug', $this->A->slug);
});

it('token SEM tenant: só os fluxos de autenticação; nenhum endpoint de negócio, mesmo com abilities', function () {
    $semTenant = $this->adminA->createToken('sem-tenant', ['tenant.view', 'settings.manage', 'audit.view'], now()->addDay());
    $t = $semTenant->plainTextToken;

    foreach (['/api/v1/tenant', '/api/v1/tenant/settings', '/api/v1/audit-logs', '/api/v1/members', '/api/v1/roles', '/api/v1/tokens', '/api/v1/invitations'] as $uri) {
        $this->withToken($t)->getJson($uri)->assertForbidden()->assertJsonPath('error.code', 'tenant_required');
    }

    $this->withToken($t)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('active_tenant', null)->assertJsonPath('permissions', null);
    $this->withToken($t)->getJson('/api/v1/auth/tenants')->assertOk();
});

it('a expiração continua obrigatória e limitada a 90 dias', function () {
    issueWith($this->browser, ['tenant.view'], 91)->assertStatus(422);
    issueWith($this->browser, ['tenant.view'], 0)->assertStatus(422);
    $this->browser->post('/api/v1/tokens', ['name' => 'x', 'abilities' => ['tenant.view']])->assertStatus(422);

    $res = issueWith($this->browser, ['tenant.view'], 90)->assertCreated();
    expect(PersonalAccessToken::query()->find($res->json('data.id'))->expires_at->isFuture())->toBeTrue();
});

it('a revogação é individual: revogar um token não afeta os outros', function () {
    $a = issueWith($this->browser, ['tenant.view'])->assertCreated();
    $b = issueWith($this->browser, ['tenant.view'])->assertCreated();

    $this->browser->delete('/api/v1/tokens/'.$a->json('data.id'))->assertNoContent();

    $this->withToken($a->json('token'))->getJson('/api/v1/tenant')->assertUnauthorized();
    $this->withToken($b->json('token'))->getJson('/api/v1/tenant')->assertOk();
});

// ---------------------------------------------------------------- nenhum token contorna o SetTenantContext
it('TODA rota de negócio passa pelo SetTenantContext (estrutural: rota nova sem o grupo quebra este teste)', function () {
    $publicas = ['api/v1/health', 'api/v1/health/ready', 'api/v1/auth/login', 'api/v1/auth/forgot-password', 'api/v1/auth/reset-password', 'api/v1/invitations/accept'];
    $semTenant = ['api/v1/auth/logout', 'api/v1/auth/me', 'api/v1/auth/tenants', 'api/v1/auth/logout-others', 'api/v1/session/tenant'];

    $naoProtegidas = [];
    $cobertas = 0;

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/') || in_array($uri, $publicas, true) || in_array($uri, $semTenant, true)) {
            continue;
        }

        $middleware = Route::resolveMiddleware($route->gatherMiddleware());
        if (! in_array(SetTenantContext::class, $middleware, true)) {
            $naoProtegidas[] = $route->methods()[0].' '.$uri;
        } else {
            $cobertas++;
        }
    }

    expect($naoProtegidas)->toBe([], 'rotas de negócio SEM SetTenantContext: '.implode(', ', $naoProtegidas))
        ->and($cobertas)->toBeGreaterThan(15);
});

it('as rotas sem tenant exigem autenticação e nunca devolvem dados de negócio', function () {
    foreach (['api/v1/auth/logout', 'api/v1/auth/me', 'api/v1/auth/tenants', 'api/v1/auth/logout-others', 'api/v1/session/tenant'] as $uri) {
        $route = collect(Route::getRoutes())->first(fn ($r) => $r->uri() === $uri);
        $middleware = Route::resolveMiddleware($route->gatherMiddleware());

        expect($middleware)->toContain('Illuminate\Auth\Middleware\Authenticate:sanctum')
            ->and($middleware)->not->toContain(SetTenantContext::class);
    }
});

it('sessões SPA continuam por cookie e não dependem de abilities', function () {
    // o admin, por sessão, usa permissões do papel sem nenhuma ability de token
    $this->browser->get('/api/v1/tenant')->assertOk();
    $this->browser->put('/api/v1/tenant/settings/sessao', ['value' => 1])->assertOk();
    $this->browser->get('/api/v1/members')->assertOk();

    expect(PersonalAccessToken::query()->count())->toBe(0); // nenhum token foi necessário
});
