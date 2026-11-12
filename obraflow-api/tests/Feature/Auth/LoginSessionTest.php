<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->tenantA = Scenario::tenant();
    $this->tenantB = Scenario::tenant();
    $this->browser = new Browser($this);
});

// ---------------------------------------------------------------- login e sessão (#16)
it('login com um único vínculo seleciona o tenant automaticamente e a sessão persiste', function () {
    $user = Scenario::member($this->tenantA, 'admin');

    $res = $this->browser->login($user->email)->assertOk();
    $res->assertJsonPath('user.email', $user->email)
        ->assertJsonPath('active_tenant.uuid', $this->tenantA->uuid)
        ->assertJsonCount(1, 'tenants');

    // a sessão por cookie mantém o usuário e o tenant entre requisições
    $this->browser->get('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('active_tenant.uuid', $this->tenantA->uuid)
        ->assertJsonFragment(['tenant.view']);
});

it('com vários vínculos NÃO seleciona automaticamente: o tenant é escolha explícita', function () {
    $user = Scenario::member($this->tenantA, 'admin');
    Scenario::attach($this->tenantB, $user, 'viewer');

    $this->browser->login($user->email)->assertOk()
        ->assertJsonPath('active_tenant', null)
        ->assertJsonCount(2, 'tenants');

    // sem tenant selecionado, dado de negócio é recusado
    $this->browser->get('/api/v1/tenant')->assertForbidden()->assertJsonPath('error.code', 'tenant_required');
});

it('login com credenciais inválidas devolve a MESMA resposta para e-mail inexistente e senha errada', function () {
    $user = Scenario::member($this->tenantA);

    $wrong = $this->browser->login($user->email, 'senha-errada-123')->assertStatus(401);
    $unknown = $this->browser->login('naoexiste@example.test', 'qualquer-coisa-123')->assertStatus(401);

    expect($wrong->json('error.code'))->toBe('invalid_credentials')
        ->and($unknown->json('error.code'))->toBe('invalid_credentials')
        ->and($wrong->json('error.message'))->toBe($unknown->json('error.message'));
});

it('login só funciona para o SPA (sem Origin/Referer stateful é recusado)', function () {
    $user = Scenario::member($this->tenantA);

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => Scenario::PASSWORD])
        ->assertForbidden()->assertJsonPath('error.code', 'stateful_required');
});

it('freia tentativas: a 6ª tentativa seguida recebe 429', function () {
    $user = Scenario::member($this->tenantA);

    foreach (range(1, 5) as $i) {
        $this->browser->login($user->email, 'errada-errada-1')->assertStatus(401);
    }

    $this->browser->login($user->email, 'errada-errada-1')->assertStatus(429);
    RateLimiter::clear('login');
});

it('logout encerra a sessão', function () {
    $user = Scenario::member($this->tenantA);
    $this->browser->login($user->email)->assertOk();

    $this->browser->post('/api/v1/auth/logout')->assertNoContent();
    $this->browser->get('/api/v1/auth/me')->assertUnauthorized();
});

it('rota autenticada sem login devolve 401 no formato padrão', function () {
    $this->browser->get('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
});

// ---------------------------------------------------------------- controle de status do usuário (#5)
it('usuário bloqueado não consegue entrar, mesmo com a senha correta', function () {
    $user = Scenario::member($this->tenantA, 'admin', ['status' => 'blocked']);

    $this->browser->login($user->email)->assertForbidden()->assertJsonPath('error.code', 'account_blocked');
});

it('usuário bloqueado durante a sessão perde o acesso na próxima requisição', function () {
    $user = Scenario::member($this->tenantA);
    $this->browser->login($user->email)->assertOk();
    $this->browser->get('/api/v1/tenant')->assertOk();

    $user->forceFill(['status' => 'blocked'])->save();

    $this->browser->get('/api/v1/tenant')->assertForbidden()->assertJsonPath('error.code', 'account_blocked');
    $this->browser->get('/api/v1/auth/me')->assertUnauthorized(); // sessão encerrada
});

// ---------------------------------------------------------------- recuperação de senha
it('forgot-password responde igual exista ou não a conta e envia e-mail só para contas reais', function () {
    Notification::fake();
    $user = Scenario::member($this->tenantA);

    $real = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);
    $fake = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ninguem@example.test'])->assertStatus(202);

    expect($real->json('message'))->toBe($fake->json('message'));
    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);
});

it('reset-password troca a senha, revoga tokens e encerra as sessões antigas', function () {
    $user = Scenario::member($this->tenantA);
    $user->createToken('antigo'); // token sem tenant, só para provar a revogação
    $this->browser->login($user->email)->assertOk();

    $token = app('auth.password.broker')->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token, 'email' => $user->email,
        'password' => 'Nova-Senha-Forte-456', 'password_confirmation' => 'Nova-Senha-Forte-456',
    ])->assertOk();

    expect(Hash::check('Nova-Senha-Forte-456', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    // a sessão aberta antes da troca de senha deixa de valer
    $this->browser->get('/api/v1/auth/me')->assertUnauthorized();

    // e a senha antiga não entra mais
    (new Browser($this))->login($user->email)->assertStatus(401);
    (new Browser($this))->login($user->email, 'Nova-Senha-Forte-456')->assertOk();

    expect(AuditLog::query()->where('event', AuditEvent::AUTH_PASSWORD_CHANGED)->where('user_id', $user->id)->exists())->toBeTrue();
});

it('reset-password recusa token inválido e senha fraca', function () {
    $user = Scenario::member($this->tenantA);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'invalido', 'email' => $user->email,
        'password' => 'Nova-Senha-Forte-456', 'password_confirmation' => 'Nova-Senha-Forte-456',
    ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_reset_token');

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => app('auth.password.broker')->createToken($user), 'email' => $user->email,
        'password' => 'curta', 'password_confirmation' => 'curta',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

// ---------------------------------------------------------------- gerenciamento de sessões
it('logout-others encerra as OUTRAS sessões e mantém a atual', function () {
    $user = Scenario::member($this->tenantA);
    $notebook = new Browser($this);
    $celular = new Browser($this);

    $notebook->login($user->email)->assertOk();
    $celular->login($user->email)->assertOk();

    $notebook->post('/api/v1/auth/logout-others', ['password' => Scenario::PASSWORD])->assertOk();

    $notebook->get('/api/v1/auth/me')->assertOk();
    $celular->get('/api/v1/auth/me')->assertUnauthorized();

    expect(AuditLog::query()->where('event', AuditEvent::AUTH_SESSIONS_REVOKED)->exists())->toBeTrue();
});

it('logout-others exige a senha atual', function () {
    $user = Scenario::member($this->tenantA);
    $this->browser->login($user->email)->assertOk();

    $this->browser->post('/api/v1/auth/logout-others', ['password' => 'errada-errada-1'])->assertStatus(422);
});

it('o login registra auth.login e last_login_at', function () {
    $user = Scenario::member($this->tenantA);
    $this->browser->login($user->email)->assertOk();

    expect(AuditLog::query()->where('event', AuditEvent::AUTH_LOGIN)->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(User::find($user->id)->last_login_at)->not->toBeNull();
});
