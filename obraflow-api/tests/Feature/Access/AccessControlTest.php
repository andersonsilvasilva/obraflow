<?php

use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Access\Services\MembershipService;
use App\Modules\Access\Services\PermissionCatalog;
use App\Modules\Audit\AuditEvent;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;

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

function membership(Tenant $tenant, User $user): TenantUser
{
    return TenantUser::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();
}

function roleId(Tenant $tenant, string $name): int
{
    return Role::query()->forTenant($tenant->id)->where('name', $name)->value('id');
}

/** Papéis do usuário NO tenant (o Spatie só enxerga papéis dentro do contexto do tenant). */
function rolesIn(Tenant $tenant, User $user): array
{
    return app(TenantContext::class)->run(
        $tenant,
        fn () => User::query()->findOrFail($user->id)->roles()->pluck('name')->sort()->values()->all(),
    );
}

// ---------------------------------------------------------------- #3 / #4 atribuição entre tenants
it('API: papel de OUTRO tenant não pode ser atribuído (404, indistinguível de inexistente)', function () {
    $colega = Scenario::member($this->A, 'viewer');
    $papelDeB = roleId($this->B, 'admin');

    $this->browser->post('/api/v1/members/'.membership($this->A, $colega)->id.'/roles', ['role_id' => $papelDeB])->assertNotFound();
    $this->browser->post('/api/v1/members/'.membership($this->A, $colega)->id.'/roles', ['role_id' => 999999])->assertNotFound();

    expect(rolesIn($this->A, $colega))->toBe(['viewer']);
});

it('API: vínculo de OUTRO tenant não é endereçável (404)', function () {
    $deB = membership($this->B, $this->adminB);

    $this->browser->post("/api/v1/members/{$deB->id}/suspend")->assertNotFound();
    $this->browser->post("/api/v1/members/{$deB->id}/roles", ['role_id' => roleId($this->A, 'viewer')])->assertNotFound();
    $this->browser->delete("/api/v1/members/{$deB->id}")->assertNotFound();

    expect($deB->fresh()->membership_status)->toBe(MembershipStatus::Active);
});

it('BANCO: atribuir ao usuário um papel de outro tenant é recusado mesmo sem passar pela API', function () {
    $papelDeB = Role::query()->findOrFail(roleId($this->B, 'admin'));
    $colega = Scenario::member($this->A, 'viewer');

    // contexto A + objeto de papel do tenant B: o pacote tentaria gravar (A, papel de B) ⇒ FK composta recusa
    $erro = app(TenantContext::class)->run($this->A, function () use ($colega, $papelDeB) {
        try {
            $colega->assignRole($papelDeB);

            return null;
        } catch (QueryException $e) {
            return $e->getPrevious()->errorInfo[1];
        }
    }, $colega);

    expect($erro)->toBe(1452);
    expect(DB::table('model_has_roles')->where('role_id', $papelDeB->id)->count())->toBe(1); // só o do admin de B
});

it('BANCO: atribuir papel a usuário SEM vínculo no tenant é recusado', function () {
    $estranho = User::factory()->create();
    $papelDeA = Role::query()->findOrFail(roleId($this->A, 'viewer'));

    $erro = app(TenantContext::class)->run($this->A, function () use ($estranho, $papelDeA) {
        try {
            $estranho->assignRole($papelDeA);

            return null;
        } catch (QueryException $e) {
            return $e->getPrevious()->errorInfo[1];
        }
    }, $estranho);

    expect($erro)->toBe(1452);
});

it('serviço: atribuir papel de outro tenant lança 404 e a atribuição legítima grava o tenant correto', function () {
    $colega = Scenario::member($this->A, 'viewer');
    $service = app(MembershipService::class);

    expect(fn () => $service->assignRole($this->adminA, $this->A, membership($this->A, $colega), Role::query()->findOrFail(roleId($this->B, 'viewer'))))
        ->toThrow(ApiException::class);

    $this->browser->post('/api/v1/members/'.membership($this->A, $colega)->id.'/roles', ['role_id' => roleId($this->A, 'member')])->assertCreated();

    $row = DB::table('model_has_roles')->where('model_id', $colega->id)->where('role_id', roleId($this->A, 'member'))->first();
    expect($row->tenant_id)->toBe($this->A->id)->and($row->model_type)->toBe('user');
});

it('atribuir papel é idempotente (não duplica nem reaudita)', function () {
    $colega = Scenario::member($this->A, 'viewer');
    $url = '/api/v1/members/'.membership($this->A, $colega)->id.'/roles';

    $this->browser->post($url, ['role_id' => roleId($this->A, 'member')])->assertCreated();
    $this->browser->post($url, ['role_id' => roleId($this->A, 'member')])->assertOk()->assertJsonPath('data.changed', false);

    expect(AuditLog::query()->where('event', AuditEvent::ROLE_ASSIGNED)->count())->toBe(1 + 0); // 1 evento (a atribuição inicial do Scenario não passa pelo serviço)
});

// ---------------------------------------------------------------- permissões efetivas
it('cada papel de sistema enxerga só o que suas permissões permitem', function () {
    $member = Scenario::member($this->A, 'member');
    $viewer = Scenario::member($this->A, 'viewer');

    $m = new Browser($this);
    $m->login($member->email)->assertOk();
    $m->get('/api/v1/members')->assertOk();
    $m->get('/api/v1/roles')->assertOk();
    $m->get('/api/v1/tenant/settings')->assertOk();
    $m->post('/api/v1/members/1/suspend')->assertForbidden();
    $m->post('/api/v1/roles', ['name' => 'x', 'permissions' => []])->assertForbidden();
    $m->post('/api/v1/invitations', ['email' => 'a@b.test', 'role_id' => 1])->assertForbidden();
    $m->get('/api/v1/audit-logs')->assertForbidden();

    $v = new Browser($this);
    $v->login($viewer->email)->assertOk();
    $v->get('/api/v1/tenant')->assertOk();
    $v->get('/api/v1/members')->assertForbidden();
    $v->get('/api/v1/roles')->assertForbidden();
});

// ---------------------------------------------------------------- papéis (CRUD) e auditoria
it('cria papel do tenant, com permissões, e audita role.created e permission.granted', function () {
    $res = $this->browser->post('/api/v1/roles', ['name' => 'Engenheiro', 'permissions' => ['tenant.view', 'settings.view']])->assertCreated();

    expect($res->json('data.permissions'))->toBe(['settings.view', 'tenant.view']);

    $role = Role::query()->findOrFail($res->json('data.id'));
    expect($role->tenant_id)->toBe($this->A->id)->and($role->is_system)->toBeFalse();

    $events = AuditLog::query()->where('entity_type', 'role')->where('entity_id', $role->id)->pluck('event')->all();
    expect($events)->toContain(AuditEvent::ROLE_CREATED, AuditEvent::PERMISSION_GRANTED);
});

it('nome de papel é único POR tenant e permissão desconhecida é recusada', function () {
    $this->browser->post('/api/v1/roles', ['name' => 'admin', 'permissions' => []])->assertStatus(422);
    $this->browser->post('/api/v1/roles', ['name' => 'Engenheiro', 'permissions' => []])->assertCreated();
    $this->browser->post('/api/v1/roles', ['name' => 'Engenheiro', 'permissions' => []])->assertStatus(422);
    $this->browser->post('/api/v1/roles', ['name' => 'Novo', 'permissions' => ['inventada.acao']])->assertStatus(422);

    // o mesmo nome em OUTRO tenant é permitido
    $b = new Browser($this);
    $b->login($this->adminB->email)->assertOk();
    $b->post('/api/v1/roles', ['name' => 'Engenheiro', 'permissions' => []])->assertCreated();
});

it('lista só os papéis do tenant ativo', function () {
    Role::create(['name' => 'so-do-b', 'guard_name' => 'web', 'tenant_id' => $this->B->id]);

    $nomes = collect($this->browser->get('/api/v1/roles')->assertOk()->json('data'))->pluck('name')->all();

    expect($nomes)->toContain('admin', 'member', 'viewer')->not->toContain('so-do-b');
    $this->browser->get('/api/v1/roles/'.roleId($this->B, 'admin'))->assertNotFound();
});

it('papéis de sistema são imutáveis', function () {
    $admin = roleId($this->A, 'admin');

    $this->browser->put("/api/v1/roles/{$admin}", ['name' => 'root'])->assertStatus(409)->assertJsonPath('error.code', 'system_role_immutable');
    $this->browser->put("/api/v1/roles/{$admin}", ['permissions' => []])->assertStatus(409);
    $this->browser->delete("/api/v1/roles/{$admin}")->assertStatus(409)->assertJsonPath('error.code', 'system_role_immutable');
});

it('altera permissões do papel e audita o diff (granted/revoked)', function () {
    $id = $this->browser->post('/api/v1/roles', ['name' => 'Gestor', 'permissions' => ['tenant.view', 'members.view']])->json('data.id');

    $this->browser->put("/api/v1/roles/{$id}", ['permissions' => ['tenant.view', 'settings.view']])->assertOk();

    $granted = AuditLog::query()->where('event', AuditEvent::PERMISSION_GRANTED)->where('entity_id', $id)->orderByDesc('id')->first();
    $revoked = AuditLog::query()->where('event', AuditEvent::PERMISSION_REVOKED)->where('entity_id', $id)->first();

    expect($granted->new_values['permissions'])->toBe(['settings.view'])
        ->and($revoked->old_values['permissions'])->toBe(['members.view']);
});

it('renomeia papel e audita role.updated com antes/depois', function () {
    $id = $this->browser->post('/api/v1/roles', ['name' => 'Gestor', 'permissions' => []])->json('data.id');

    $this->browser->put("/api/v1/roles/{$id}", ['name' => 'Gestor de Obras'])->assertOk()->assertJsonPath('data.name', 'Gestor de Obras');

    $log = AuditLog::query()->where('event', AuditEvent::ROLE_UPDATED)->firstOrFail();
    expect($log->old_values)->toBe(['name' => 'Gestor'])->and($log->new_values)->toBe(['name' => 'Gestor de Obras']);
});

it('papel em uso (atribuído ou em convite) não pode ser excluído; sem uso, sim', function () {
    $id = $this->browser->post('/api/v1/roles', ['name' => 'Temp', 'permissions' => ['tenant.view']])->json('data.id');
    $colega = Scenario::member($this->A, 'viewer');
    $this->browser->post('/api/v1/members/'.membership($this->A, $colega)->id.'/roles', ['role_id' => $id])->assertCreated();

    $this->browser->delete("/api/v1/roles/{$id}")->assertStatus(409)->assertJsonPath('error.code', 'role_in_use');

    $this->browser->delete('/api/v1/members/'.membership($this->A, $colega)->id.'/roles/'.$id)->assertNoContent();
    $this->browser->delete("/api/v1/roles/{$id}")->assertNoContent();

    expect(Role::query()->find($id))->toBeNull()
        ->and(AuditLog::query()->where('event', AuditEvent::ROLE_DELETED)->exists())->toBeTrue();
});

// ---------------------------------------------------------------- anti-escalação de privilégio
it('quem gerencia papéis NÃO concede permissões que não possui nem se promove a admin', function () {
    $gestor = Scenario::member($this->A, 'viewer');
    // papel delegado: pode gerenciar papéis e ver membros, mas NÃO tem tokens.manage nem settings.manage
    app(TenantContext::class)->run($this->A, function () use ($gestor) {
        $delegado = Role::create(['name' => 'delegado', 'guard_name' => 'web', 'tenant_id' => $this->A->id]);
        $delegado->syncPermissions(['tenant.view', 'roles.view', 'roles.manage', 'members.view']);
        $gestor->assignRole($delegado);
    }, $gestor);

    $b = new Browser($this);
    $b->login($gestor->email)->assertOk();

    $b->post('/api/v1/roles', ['name' => 'poderoso', 'permissions' => ['tokens.manage']])
        ->assertForbidden()->assertJsonPath('error.code', 'privilege_escalation');

    $b->post('/api/v1/members/'.membership($this->A, $gestor)->id.'/roles', ['role_id' => roleId($this->A, 'admin')])
        ->assertForbidden()->assertJsonPath('error.code', 'privilege_escalation');

    expect(rolesIn($this->A, $gestor))->toBe(['delegado', 'viewer'])->not->toContain('admin'); // nada de admin
});

// ---------------------------------------------------------------- vínculos
it('suspende, reativa e remove vínculos; remoção revoga papéis e tokens', function () {
    $colega = Scenario::member($this->A, 'member');
    $m = membership($this->A, $colega);
    $token = $colega->createToken('t');
    $token->accessToken->forceFill(['tenant_id' => $this->A->id])->save();

    $this->browser->post("/api/v1/members/{$m->id}/suspend")->assertOk()->assertJsonPath('data.status', 'suspended');
    expect(PersonalAccessToken::query()->count())->toBe(0); // tokens do tenant revogados na suspensão

    $this->browser->post("/api/v1/members/{$m->id}/suspend")->assertStatus(409);
    $this->browser->post("/api/v1/members/{$m->id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');

    $this->browser->delete("/api/v1/members/{$m->id}")->assertNoContent();
    expect($m->fresh()->membership_status)->toBe(MembershipStatus::Removed)
        ->and(DB::table('model_has_roles')->where('tenant_id', $this->A->id)->where('model_id', $colega->id)->count())->toBe(0);

    $this->browser->post("/api/v1/members/{$m->id}/reactivate")->assertStatus(409); // removido: só por novo convite

    $events = AuditLog::query()->whereIn('event', [AuditEvent::MEMBERSHIP_SUSPENDED, AuditEvent::MEMBERSHIP_REACTIVATED, AuditEvent::MEMBERSHIP_REMOVED, AuditEvent::ROLE_REVOKED])->pluck('event')->all();
    expect($events)->toContain(AuditEvent::MEMBERSHIP_SUSPENDED, AuditEvent::MEMBERSHIP_REACTIVATED, AuditEvent::MEMBERSHIP_REMOVED, AuditEvent::ROLE_REVOKED);
});

it('lista membros com papéis apenas do tenant ativo', function () {
    Scenario::member($this->A, 'viewer');
    Scenario::member($this->B, 'viewer');

    $dados = $this->browser->get('/api/v1/members')->assertOk()->json('data');

    expect($dados)->toHaveCount(2);
    expect(collect($dados)->pluck('user.email')->all())->not->toContain($this->adminB->email);
});

// ---------------------------------------------------------------- #15 último administrador
it('protege o último administrador ativo: suspender, remover e retirar o papel são recusados', function () {
    $m = membership($this->A, $this->adminA);

    $this->browser->post("/api/v1/members/{$m->id}/suspend")->assertStatus(409)->assertJsonPath('error.code', 'last_admin');
    $this->browser->delete("/api/v1/members/{$m->id}")->assertStatus(409)->assertJsonPath('error.code', 'last_admin');
    $this->browser->delete("/api/v1/members/{$m->id}/roles/".roleId($this->A, 'admin'))->assertStatus(409)->assertJsonPath('error.code', 'last_admin');

    expect($m->fresh()->membership_status)->toBe(MembershipStatus::Active)->and(rolesIn($this->A, $this->adminA))->toBe(['admin']);
});

it('com dois administradores, um pode sair — e o outro passa a ser o último', function () {
    $segundo = Scenario::member($this->A, 'admin');
    $mSegundo = membership($this->A, $segundo);
    $mPrimeiro = membership($this->A, $this->adminA);

    $this->browser->post("/api/v1/members/{$mSegundo->id}/suspend")->assertOk();

    $this->browser->post("/api/v1/members/{$mPrimeiro->id}/suspend")->assertStatus(409)->assertJsonPath('error.code', 'last_admin');

    // reativar o segundo e então o primeiro pode ser removido
    $this->browser->post("/api/v1/members/{$mSegundo->id}/reactivate")->assertOk();
    $this->browser->delete("/api/v1/members/{$mPrimeiro->id}")->assertNoContent();
});

it('administrador suspenso ou bloqueado NÃO conta como administrador ativo', function () {
    $segundo = Scenario::member($this->A, 'admin');
    $segundo->forceFill(['status' => 'blocked'])->save(); // bloqueado: não conta

    $this->browser->post('/api/v1/members/'.membership($this->A, $this->adminA)->id.'/suspend')
        ->assertStatus(409)->assertJsonPath('error.code', 'last_admin');
});

it('a proteção roda sob lock de linha no tenant (anti-corrida)', function () {
    $segundo = Scenario::member($this->A, 'admin');

    DB::enableQueryLog();
    $this->browser->post('/api/v1/members/'.membership($this->A, $segundo)->id.'/suspend')->assertOk();
    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect($sql)->toMatch('/from `tenants`.*for update/is');
});

// ---------------------------------------------------------------- catálogo de permissões
it('o catálogo de permissões é idempotente e não apaga nada', function () {
    $antes = DB::table('permissions')->count();
    DB::table('permissions')->insert(['name' => 'custom.legado', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);

    $r1 = app(PermissionCatalog::class)->sync();
    $r2 = app(PermissionCatalog::class)->sync();

    expect($r1['created'])->toBeEmpty()->and($r2['created'])->toBeEmpty()
        ->and(DB::table('permissions')->count())->toBe($antes + 1)
        ->and(DB::table('permissions')->where('name', 'custom.legado')->exists())->toBeTrue()
        ->and(DB::table('permissions')->whereNull('module')->where('name', '<>', 'custom.legado')->count())->toBe(0);
});

it('GET /permissions devolve o catálogo global', function () {
    $nomes = collect($this->browser->get('/api/v1/permissions')->assertOk()->json('data'))->pluck('name')->all();

    expect($nomes)->toEqualCanonicalizing(array_keys(PermissionCatalog::PERMISSIONS));
});
