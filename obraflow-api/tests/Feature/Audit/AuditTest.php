<?php

use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Access\Mail\InvitationMail;
use App\Modules\Access\Services\ProvisionTenant;
use App\Modules\Audit\AuditEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;

beforeEach(function () {
    RateLimiter::clear('login');
    RateLimiter::clear('password-reset');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
    $this->adminA = Scenario::member($this->A, 'admin');
    $this->browser = new Browser($this);
});

/** Faz o listener do banco falhar ao gravar auditoria enquanto $flag['on'] for true (simula auditoria indisponível). */
function failAuditWhile(array &$flag): void
{
    // beforeExecuting impede a execução do INSERT (auditoria realmente indisponível: a linha NUNCA é gravada).
    DB::connection()->beforeExecuting(function (string $query) use (&$flag) {
        if ($flag['on'] && str_contains($query, 'insert into `audit_logs`')) {
            throw new RuntimeException('auditoria indisponível (simulada)');
        }
    });
}

// ---------------------------------------------------------------- #13 cobertura do catálogo
it('TODO evento do catálogo é de fato emitido por alguma operação real', function () {
    Mail::fake();
    Notification::fake();

    // --- provisionamento (tenant.provisioned, membership.created, role.created, permission.granted, role.assigned)
    $r = app(ProvisionTenant::class)->run('Obra Total Demo', 'obra-total', 'dono@total.test', 'Dono Total', null);
    $dono = $r['admin'];
    $senha = $r['generated_password'];
    $tenant = $r['tenant'];

    // --- login ruim, login bom (seleção automática), seleção negada
    $b = new Browser($this);
    $b->login($dono->email, 'errada-errada-1')->assertStatus(401);                 // auth.login_failed
    $b->login($dono->email, $senha)->assertOk();                                    // auth.login, tenant.selected
    $b->post('/api/v1/session/tenant', ['tenant_uuid' => $this->A->uuid])->assertForbidden(); // tenant.selection_denied

    // --- configuração, papéis e permissões
    $b->put('/api/v1/tenant/settings/moeda', ['value' => 'BRL'])->assertOk();       // tenant.updated
    $papel = $b->post('/api/v1/roles', ['name' => 'Engenharia', 'permissions' => ['tenant.view', 'members.view']])->assertCreated()->json('data.id');
    $b->put("/api/v1/roles/{$papel}", ['name' => 'Engenharia 2', 'permissions' => ['tenant.view']])->assertOk(); // role.updated, permission.revoked
    $descartavel = $b->post('/api/v1/roles', ['name' => 'Descartavel', 'permissions' => []])->json('data.id');
    $b->delete("/api/v1/roles/{$descartavel}")->assertNoContent();                  // role.deleted

    // --- tokens
    $tok = $b->post('/api/v1/tokens', ['name' => 'a', 'expires_in_days' => 5])->assertCreated()->json('data.id'); // token.created
    $b->delete("/api/v1/tokens/{$tok}")->assertNoContent();                         // token.revoked
    $expirado = $dono->createToken('vencido', ['*'], now()->subMinute());
    $expirado->accessToken->forceFill(['tenant_id' => $tenant->id])->save();
    $this->withToken($expirado->plainTextToken)->getJson('/api/v1/tenant')->assertUnauthorized(); // token.expired_use_denied

    // --- convites: aceito, revogado e expirado
    $convidar = function (string $email) use ($b, $tenant) {
        $id = $b->post('/api/v1/invitations', ['email' => $email, 'role_id' => Role::query()->forTenant($tenant->id)->where('name', 'member')->value('id')])->assertCreated()->json('data.id');
        $token = null;
        Mail::assertSent(InvitationMail::class, function (InvitationMail $m) use ($email, &$token) {
            if ($m->hasTo($email)) {
                $token = $m->token;
            }

            return true;
        });

        return [$id, $token];
    };
    [, $tokenAceito] = $convidar('aceito@total.test');
    $this->postJson('/api/v1/invitations/accept', ['token' => $tokenAceito, 'name' => 'Aceito', 'password' => 'Aceito-Senha-123', 'password_confirmation' => 'Aceito-Senha-123'])->assertCreated(); // invitation.accepted, membership.created, role.assigned
    [$idRevogado] = $convidar('revogado@total.test');
    $b->delete("/api/v1/invitations/{$idRevogado}")->assertNoContent();             // invitation.revoked
    [$idVencido] = $convidar('vencido@total.test');
    DB::table('user_invitations')->where('id', $idVencido)->update(['expires_at' => now()->subDay()]);
    Artisan::call('obraflow:invitations:expire');                                   // invitation.expired

    // --- vínculos: suspender, reativar, remover (role.revoked)
    $aceito = User::query()->where('email', 'aceito@total.test')->firstOrFail();
    $vinculo = TenantUser::query()->where('tenant_id', $tenant->id)->where('user_id', $aceito->id)->firstOrFail()->id;
    $b->post("/api/v1/members/{$vinculo}/suspend")->assertOk();                     // membership.suspended
    $b->post("/api/v1/members/{$vinculo}/reactivate")->assertOk();                  // membership.reactivated
    $b->post("/api/v1/members/{$vinculo}/roles", ['role_id' => $papel])->assertCreated();
    $b->delete("/api/v1/members/{$vinculo}")->assertNoContent();                    // membership.removed, role.revoked

    // --- senha e sessões
    $aceito->forceFill(['password' => 'Aceito-Senha-123'])->save();
    Password::sendResetLink(['email' => $aceito->email]);                           // (notificação falsa)
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $aceito->email])->assertStatus(202); // auth.password_reset_requested
    $this->postJson('/api/v1/auth/reset-password', ['token' => app('auth.password.broker')->createToken($aceito), 'email' => $aceito->email, 'password' => 'Outra-Senha-456', 'password_confirmation' => 'Outra-Senha-456'])->assertOk(); // auth.password_changed
    $b->post('/api/v1/auth/logout-others', ['password' => $senha])->assertOk();     // auth.sessions_revoked
    $b->post('/api/v1/auth/logout')->assertNoContent();                             // auth.logout

    // --- administração via CLI
    $outro = Scenario::member($tenant, 'admin');
    Artisan::call('obraflow:user:block', ['email' => $outro->email]);               // auth.user_blocked
    Artisan::call('obraflow:user:unblock', ['email' => $outro->email]);             // auth.user_unblocked
    Artisan::call('obraflow:tenant:status', ['slug' => 'obra-total', 'status' => 'suspended']);  // tenant.suspended
    Artisan::call('obraflow:tenant:status', ['slug' => 'obra-total', 'status' => 'active']);     // tenant.reactivated

    $emitidos = AuditLog::query()->distinct()->pluck('event')->all();
    $faltando = array_values(array_diff(AuditEvent::all(), $emitidos));

    expect($faltando)->toBe([], 'eventos do catálogo que NENHUMA operação emite: '.implode(', ', $faltando));
});

// ---------------------------------------------------------------- atomicidade (mesma transação)
it('evento de AUTORIZAÇÃO: se a auditoria falhar, a operação inteira é desfeita', function () {
    $flag = ['on' => false];
    failAuditWhile($flag);

    $segundoAdmin = Scenario::member($this->A, 'admin');
    $m = TenantUser::query()->where('tenant_id', $this->A->id)->where('user_id', $segundoAdmin->id)->firstOrFail();
    $t = $segundoAdmin->createToken('t');
    $t->accessToken->forceFill(['tenant_id' => $this->A->id])->save();

    $this->browser->login($this->adminA->email)->assertOk();
    $flag['on'] = true;

    $this->browser->post("/api/v1/members/{$m->id}/suspend")->assertStatus(500);
    $this->browser->post('/api/v1/roles', ['name' => 'Fantasma', 'permissions' => ['tenant.view']])->assertStatus(500);

    $flag['on'] = false;
    expect($m->fresh()->membership_status)->toBe(MembershipStatus::Active)  // suspensão desfeita
        ->and(PersonalAccessToken::query()->count())->toBe(1)               // token NÃO foi revogado
        ->and(Role::query()->forTenant($this->A->id)->where('name', 'Fantasma')->exists())->toBeFalse();
});

it('troca de tenant: se a auditoria falhar, a SESSÃO também não muda (nunca vale sem registro)', function () {
    $flag = ['on' => false];
    failAuditWhile($flag);
    Scenario::attach($this->B, $this->adminA, 'viewer');

    $this->browser->login($this->adminA->email)->assertOk();
    $this->browser->post('/api/v1/session/tenant', ['tenant_uuid' => $this->A->uuid])->assertOk();

    $flag['on'] = true;
    $this->browser->post('/api/v1/session/tenant', ['tenant_uuid' => $this->B->uuid])->assertStatus(500);
    $flag['on'] = false;

    $this->browser->get('/api/v1/auth/me')->assertOk()->assertJsonPath('active_tenant.uuid', $this->A->uuid); // continua em A
    expect(TenantUser::query()->where('tenant_id', $this->B->id)->where('user_id', $this->adminA->id)->value('last_used_at'))->toBeNull();
});

it('eventos auth.*: falha de auditoria NÃO derruba o login, mas nenhum tenant é ativado sem registro', function () {
    $flag = ['on' => true];
    failAuditWhile($flag);

    // entrou apesar da falha (melhor esforço), porém SEM tenant ativo: a seleção é evento de autorização
    $this->browser->login($this->adminA->email)->assertOk()->assertJsonPath('active_tenant', null);
    $this->browser->get('/api/v1/tenant')->assertForbidden()->assertJsonPath('error.code', 'tenant_required');

    // enquanto a auditoria estiver fora, a seleção explícita também é recusada (falha fechada)
    $this->browser->post('/api/v1/session/tenant', ['tenant_uuid' => $this->A->uuid])->assertStatus(500);

    // auditoria de volta: a seleção funciona e é registrada
    $flag['on'] = false;
    $this->browser->post('/api/v1/session/tenant', ['tenant_uuid' => $this->A->uuid])->assertOk();
    expect(AuditLog::query()->where('event', AuditEvent::TENANT_SELECTED)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::AUTH_LOGIN)->count())->toBe(0); // o login não foi registrado (melhor esforço)
});

// ---------------------------------------------------------------- conteúdo e contexto
it('registra o contexto da requisição (request_id, IP, user agent, tenant e ator)', function () {
    $this->browser->login($this->adminA->email)->assertOk();

    $this->browser->put('/api/v1/tenant/settings/moeda', ['value' => 'BRL'], ['X-Request-Id' => 'req-abc12345', 'User-Agent' => 'ObraFlowTest/1.0'])->assertOk();

    $log = AuditLog::query()->where('event', AuditEvent::TENANT_UPDATED)->firstOrFail();
    expect($log->request_id)->toBe('req-abc12345')
        ->and($log->ipText())->toBe('127.0.0.1')
        ->and($log->user_agent)->toBe('ObraFlowTest/1.0')
        ->and($log->tenant_id)->toBe($this->A->id)
        ->and($log->user_id)->toBe($this->adminA->id)
        ->and($log->new_values)->toBe(['key' => 'moeda', 'value' => 'BRL'])
        ->and($log->created_at)->not->toBeNull();
});

it('login falho NÃO grava o e-mail em claro nem a senha — só o HMAC', function () {
    $this->browser->login('alvo@exemplo.test', 'Senha-Que-Nao-Deve-Vazar-1')->assertStatus(401);

    $log = AuditLog::query()->where('event', AuditEvent::AUTH_LOGIN_FAILED)->firstOrFail();
    $json = json_encode($log->toArray());

    expect($json)->not->toContain('alvo@exemplo.test')->not->toContain('Senha-Que-Nao-Deve-Vazar-1')
        ->and($log->metadata['email_hmac'])->toHaveLength(64)
        ->and($log->tenant_id)->toBeNull();
});

it('nenhum registro de auditoria contém senha, token ou hash, mesmo após o roteiro completo de operações', function () {
    $this->browser->login($this->adminA->email)->assertOk();
    $plain = $this->browser->post('/api/v1/tokens', ['name' => 't', 'expires_in_days' => 3])->json('token');
    $this->browser->post('/api/v1/auth/logout-others', ['password' => Scenario::PASSWORD])->assertOk();

    $json = json_encode(AuditLog::query()->get()->toArray());

    expect($json)->not->toContain($plain)->not->toContain(Scenario::PASSWORD)
        ->and($json)->not->toContain(explode('|', $plain)[1])
        ->and($json)->not->toContain(User::query()->find($this->adminA->id)->password);
});

// ---------------------------------------------------------------- consulta (escopo e permissão)
it('a consulta de auditoria exige audit.view e só mostra o tenant ativo', function () {
    $adminB = Scenario::member($this->B, 'admin');
    $b = new Browser($this);
    $b->login($adminB->email)->assertOk();
    $b->put('/api/v1/tenant/settings/so-do-b', ['value' => 1])->assertOk();

    $this->browser->login($this->adminA->email)->assertOk();
    $this->browser->put('/api/v1/tenant/settings/so-do-a', ['value' => 1])->assertOk();

    $res = $this->browser->get('/api/v1/audit-logs')->assertOk();
    $texto = $res->getContent();

    expect($texto)->toContain('so-do-a')->not->toContain('so-do-b');
    expect(collect($res->json('data'))->pluck('event')->all())->not->toContain(AuditEvent::AUTH_LOGIN_FAILED); // eventos sem tenant não aparecem
    expect($res->json('meta.total'))->toBeGreaterThan(0);

    $filtrado = $this->browser->get('/api/v1/audit-logs?event='.AuditEvent::TENANT_UPDATED)->assertOk()->json('data');
    expect(collect($filtrado)->pluck('event')->unique()->all())->toBe([AuditEvent::TENANT_UPDATED]);

    $membro = Scenario::member($this->A, 'member');
    $m = new Browser($this);
    $m->login($membro->email)->assertOk();
    $m->get('/api/v1/audit-logs')->assertForbidden();
});

it('a API não oferece nenhuma forma de alterar ou excluir auditoria', function () {
    $this->browser->login($this->adminA->email)->assertOk();
    $id = AuditLog::query()->firstOrFail()->id;

    foreach (['PUT', 'PATCH', 'DELETE', 'POST'] as $method) {
        $status = $this->browser->request($method, "/api/v1/audit-logs/{$id}")->status();
        expect($status)->toBeIn([404, 405], "{$method} /audit-logs/{id} respondeu {$status}");
    }
    expect($this->browser->request('POST', '/api/v1/audit-logs', ['event' => 'x'])->status())->toBeIn([404, 405]);
});

it('a aplicação não consegue alterar ou excluir registros de auditoria (modelo e query builder)', function () {
    $log = AuditLog::query()->forceCreate(['event' => 'auth.login']);

    expect(fn () => $log->delete())->toThrow(LogicException::class)
        ->and(fn () => AuditLog::query()->where('id', $log->id)->update(['event' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => AuditLog::query()->where('id', $log->id)->delete())->toThrow(LogicException::class)
        ->and(AuditLog::query()->find($log->id)->event)->toBe('auth.login');
});

it('as operações de auditoria registram o tenant correto em cada evento de acesso', function () {
    $this->browser->login($this->adminA->email)->assertOk();
    $this->browser->post('/api/v1/roles', ['name' => 'X', 'permissions' => []])->assertCreated();

    $porTenant = AuditLog::query()->whereNotNull('tenant_id')->pluck('tenant_id')->unique()->all();
    expect($porTenant)->toBe([$this->A->id]);
});
