<?php

use App\Enums\InvitationStatus;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\UserInvitation;
use App\Modules\Access\Mail\InvitationMail;
use App\Modules\Audit\AuditEvent;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;

beforeEach(function () {
    RateLimiter::clear('login');
    RateLimiter::clear('password-reset');
    Mail::fake();
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
    $this->adminA = Scenario::member($this->A, 'admin');
    $this->adminB = Scenario::member($this->B, 'admin');
    $this->browser = new Browser($this);
    $this->browser->login($this->adminA->email)->assertOk();
});

function invRole(Tenant $tenant, string $name): int
{
    return Role::query()->forTenant($tenant->id)->where('name', $name)->value('id');
}

/** Cria o convite pela API e devolve [id, token em claro capturado do e-mail]. */
function invite(object $test, Browser $as, Tenant $tenant, string $email, string $role = 'member'): array
{
    $res = $as->post('/api/v1/invitations', ['email' => $email, 'role_id' => invRole($tenant, $role)])->assertCreated();

    $token = null;
    Mail::assertSent(InvitationMail::class, function (InvitationMail $m) use (&$token, $email) {
        if ($m->hasTo(strtolower($email))) { // o serviço normaliza o e-mail para minúsculas
            $token = $m->token;
        }

        return true;
    });

    return [$res->json('data.id'), $token];
}

const NEW_PASS = 'Convidado-Senha-789';

// ---------------------------------------------------------------- criação
it('cria convite: o token só vai por e-mail e o banco guarda apenas o hash', function () {
    [$id, $token] = invite($this, $this->browser, $this->A, 'Novo@Obra.Test');

    $res = $this->browser->get('/api/v1/invitations')->assertOk();
    expect($res->getContent())->not->toContain($token); // a API nunca devolve o token

    $row = DB::table('user_invitations')->find($id);
    expect($row->token_hash)->toBe(hash('sha256', $token))->and($row->token_hash)->not->toBe($token)
        ->and($row->email)->toBe('novo@obra.test') // normalizado
        ->and($row->status)->toBe('pending')
        ->and($row->tenant_id)->toBe($this->A->id);

    $log = AuditLog::query()->where('event', AuditEvent::INVITATION_CREATED)->firstOrFail();
    expect($log->tenant_id)->toBe($this->A->id)->and(json_encode($log->toArray()))->not->toContain($token);
});

it('recusa convite duplicado, para membro ativo, para suspenso, e com papel de outro tenant', function () {
    invite($this, $this->browser, $this->A, 'dup@obra.test');
    $this->browser->post('/api/v1/invitations', ['email' => 'DUP@obra.test', 'role_id' => invRole($this->A, 'member')])
        ->assertStatus(409)->assertJsonPath('error.code', 'invitation_pending');

    $ativo = Scenario::member($this->A, 'viewer');
    $this->browser->post('/api/v1/invitations', ['email' => $ativo->email, 'role_id' => invRole($this->A, 'member')])
        ->assertStatus(409)->assertJsonPath('error.code', 'already_member');

    $suspenso = Scenario::member($this->A, 'viewer', status: MembershipStatus::Suspended);
    $this->browser->post('/api/v1/invitations', ['email' => $suspenso->email, 'role_id' => invRole($this->A, 'member')])
        ->assertStatus(409)->assertJsonPath('error.code', 'membership_suspended');

    $this->browser->post('/api/v1/invitations', ['email' => 'x@obra.test', 'role_id' => invRole($this->B, 'admin')])->assertNotFound();
});

it('exige a permissão members.invite e impede convidar com papel acima do próprio', function () {
    $membro = Scenario::member($this->A, 'member');
    $b = new Browser($this);
    $b->login($membro->email)->assertOk();
    $b->post('/api/v1/invitations', ['email' => 'a@b.test', 'role_id' => invRole($this->A, 'viewer')])->assertForbidden();

    // quem pode convidar, mas não possui as permissões do papel, não convida com ele
    $convidador = Scenario::member($this->A, 'viewer');
    app(TenantContext::class)->run($this->A, function () use ($convidador) {
        $r = Role::create(['name' => 'recrutador', 'guard_name' => 'web', 'tenant_id' => $this->A->id]);
        $r->syncPermissions(['tenant.view', 'members.invite', 'members.view', 'roles.view']);
        $convidador->assignRole($r);
    }, $convidador);

    $c = new Browser($this);
    $c->login($convidador->email)->assertOk();
    $c->post('/api/v1/invitations', ['email' => 'e@b.test', 'role_id' => invRole($this->A, 'admin')])
        ->assertForbidden()->assertJsonPath('error.code', 'privilege_escalation');
});

it('um tenant não lista nem revoga convites de outro', function () {
    [$idB] = (function () {
        $b = new Browser($this);
        $b->login($this->adminB->email)->assertOk();

        return invite($this, $b, $this->B, 'deb@obra.test');
    })();

    expect($this->browser->get('/api/v1/invitations')->json('data'))->toBeEmpty();
    $this->browser->delete("/api/v1/invitations/{$idB}")->assertNotFound();
    expect(UserInvitation::query()->withoutGlobalScopes()->find($idB)->status)->toBe(InvitationStatus::Pending);
});

// ---------------------------------------------------------------- aceite
it('aceita convite de conta nova: cria usuário, vínculo ativo, papel e audita tudo', function () {
    [$id, $token] = invite($this, $this->browser, $this->A, 'recem@obra.test', 'member');

    $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'Recém Chegado', 'password' => NEW_PASS, 'password_confirmation' => NEW_PASS])
        ->assertCreated()->assertJsonPath('data.tenant.uuid', $this->A->uuid)->assertJsonPath('data.account_existed', false);

    $user = User::query()->where('email', 'recem@obra.test')->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull()
        ->and(TenantUser::query()->where('tenant_id', $this->A->id)->where('user_id', $user->id)->value('membership_status'))->toBe(MembershipStatus::Active)
        ->and(DB::table('model_has_roles')->where('tenant_id', $this->A->id)->where('model_id', $user->id)->value('role_id'))->toBe(invRole($this->A, 'member'))
        ->and(UserInvitation::query()->withoutGlobalScopes()->find($id)->status)->toBe(InvitationStatus::Accepted);

    $events = AuditLog::query()->where('tenant_id', $this->A->id)->pluck('event')->all();
    expect($events)->toContain(AuditEvent::INVITATION_ACCEPTED, AuditEvent::MEMBERSHIP_CREATED, AuditEvent::ROLE_ASSIGNED);

    // e a pessoa consegue entrar
    (new Browser($this))->login('recem@obra.test', NEW_PASS)->assertOk()->assertJsonPath('active_tenant.uuid', $this->A->uuid);
});

it('o token só vale uma vez; token desconhecido responde igual', function () {
    [, $token] = invite($this, $this->browser, $this->A, 'uma@obra.test');
    $payload = ['token' => $token, 'name' => 'Uma Vez', 'password' => NEW_PASS, 'password_confirmation' => NEW_PASS];

    $this->postJson('/api/v1/invitations/accept', $payload)->assertCreated();
    $usado = $this->postJson('/api/v1/invitations/accept', $payload)->assertStatus(410);
    $falso = $this->postJson('/api/v1/invitations/accept', ['token' => 'inexistente'] + $payload)->assertStatus(410);

    expect($usado->json('error.code'))->toBe('invitation_invalid')
        ->and($falso->json('error.code'))->toBe('invitation_invalid')
        ->and($usado->json('error.message'))->toBe($falso->json('error.message')); // não revela o estado do token
});

it('aceite por conta JÁ EXISTENTE (outro tenant): sem senha, mantém os dois vínculos e papéis por tenant', function () {
    $existente = Scenario::member($this->B, 'viewer');
    [, $token] = invite($this, $this->browser, $this->A, $existente->email, 'member');

    $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertCreated()->assertJsonPath('data.account_existed', true);

    $porTenant = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('model_id', $existente->id)->pluck('roles.name', 'model_has_roles.tenant_id');
    expect($porTenant[$this->A->id])->toBe('member')->and($porTenant[$this->B->id])->toBe('viewer')
        ->and(User::query()->where('email', $existente->email)->count())->toBe(1);
});

it('conta nova exige nome e senha forte', function () {
    [, $token] = invite($this, $this->browser, $this->A, 'fraco@obra.test');

    $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertStatus(422);
    $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'X', 'password' => 'curta', 'password_confirmation' => 'curta'])->assertStatus(422);

    expect(User::query()->where('email', 'fraco@obra.test')->exists())->toBeFalse()
        ->and(UserInvitation::query()->withoutGlobalScopes()->where('email', 'fraco@obra.test')->value('status'))->toBe(InvitationStatus::Pending);
});

it('usuário bloqueado não aceita convite', function () {
    $bloqueado = User::factory()->blocked()->create();
    [, $token] = invite($this, $this->browser, $this->A, $bloqueado->email);

    $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertForbidden()->assertJsonPath('error.code', 'account_blocked');
});

it('tenant suspenso não aceita convites', function () {
    [, $token] = invite($this, $this->browser, $this->A, 'susp@obra.test');
    $this->A->forceFill(['status' => 'suspended'])->save();

    $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'S', 'password' => NEW_PASS, 'password_confirmation' => NEW_PASS])
        ->assertForbidden()->assertJsonPath('error.code', 'tenant_suspended');
});

it('quem foi removido e é convidado de novo volta com SÓ o papel do novo convite', function () {
    $ex = Scenario::member($this->A, 'admin'); // 2º admin para poder removê-lo
    $m = TenantUser::query()->where('tenant_id', $this->A->id)->where('user_id', $ex->id)->firstOrFail();
    $this->browser->delete("/api/v1/members/{$m->id}")->assertNoContent();

    [, $token] = invite($this, $this->browser, $this->A, $ex->email, 'viewer');
    $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertCreated();

    $papeis = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('model_id', $ex->id)->where('model_has_roles.tenant_id', $this->A->id)->pluck('roles.name')->all();
    expect($papeis)->toBe(['viewer']) // o admin antigo NÃO volta
        ->and($m->fresh()->membership_status)->toBe(MembershipStatus::Active)
        ->and(AuditLog::query()->where('event', AuditEvent::MEMBERSHIP_REACTIVATED)->exists())->toBeTrue();
});

// ---------------------------------------------------------------- revogação e expiração
it('revoga convite pendente: o token deixa de valer e o evento é auditado', function () {
    [$id, $token] = invite($this, $this->browser, $this->A, 'rev@obra.test');

    $this->browser->delete("/api/v1/invitations/{$id}")->assertNoContent();
    $this->browser->delete("/api/v1/invitations/{$id}")->assertStatus(409);

    $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'R', 'password' => NEW_PASS, 'password_confirmation' => NEW_PASS])
        ->assertStatus(410)->assertJsonPath('error.code', 'invitation_invalid');

    expect(AuditLog::query()->where('event', AuditEvent::INVITATION_REVOKED)->count())->toBe(1);
    // com o convite revogado é possível convidar o mesmo e-mail de novo
    invite($this, $this->browser, $this->A, 'rev@obra.test');
});

it('convite vencido: aceite tardio responde 410 e marca como expirado', function () {
    [$id, $token] = invite($this, $this->browser, $this->A, 'tarde@obra.test');

    $this->travel(8)->days(); // validade padrão: 7 dias

    $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'T', 'password' => NEW_PASS, 'password_confirmation' => NEW_PASS])
        ->assertStatus(410)->assertJsonPath('error.code', 'invitation_expired');

    expect(UserInvitation::query()->withoutGlobalScopes()->find($id)->status)->toBe(InvitationStatus::Expired)
        ->and(AuditLog::query()->where('event', AuditEvent::INVITATION_EXPIRED)->count())->toBe(1);
});

it('a rotina agendada expira os vencidos e preserva os válidos', function () {
    [$vencido] = invite($this, $this->browser, $this->A, 'v1@obra.test');
    [$valido] = invite($this, $this->browser, $this->A, 'v2@obra.test');
    // o salto de tempo invalida as sessões em arquivo; então o convite "válido" nasce com validade longa
    DB::table('user_invitations')->where('id', $valido)->update(['expires_at' => now()->addDays(30)]);

    $this->travel(8)->days(); // só o v1 (7 dias) vence

    expect(Artisan::call('obraflow:invitations:expire'))->toBe(0);

    expect(UserInvitation::query()->withoutGlobalScopes()->find($vencido)->status)->toBe(InvitationStatus::Expired)
        ->and(UserInvitation::query()->withoutGlobalScopes()->find($valido)->status)->toBe(InvitationStatus::Pending);

    $log = AuditLog::query()->where('event', AuditEvent::INVITATION_EXPIRED)->firstOrFail();
    expect($log->user_id)->toBeNull()->and($log->metadata['source'])->toBe('system');

    Artisan::call('obraflow:invitations:expire'); // idempotente
    expect(AuditLog::query()->where('event', AuditEvent::INVITATION_EXPIRED)->count())->toBe(1);
});

it('o aceite é público mas tem freio de tentativas', function () {
    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/invitations/accept', ['token' => "chute-{$i}"])->assertStatus(410);
    }

    $this->postJson('/api/v1/invitations/accept', ['token' => 'chute-4'])->assertStatus(429);
});
