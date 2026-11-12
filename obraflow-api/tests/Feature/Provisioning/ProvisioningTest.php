<?php

use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Access\Exceptions\ProvisioningException;
use App\Modules\Access\Services\PermissionCatalog;
use App\Modules\Access\Services\ProvisionTenant;
use App\Modules\Audit\AuditEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Console\Exception\InvalidOptionException as ConsoleException;
use Tests\Support\Browser;
use Tests\Support\Scenario;

beforeEach(function () {
    RateLimiter::clear('login');
});

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing'); // desfaz simulações de produção
});

/** @return array{0: int, 1: string} código de saída e saída do comando */
function provision(array $options): array
{
    // --no-interaction: Artisan::call é interativo por padrão e ficaria esperando a senha no terminal
    $code = Artisan::call('obraflow:provision', $options + ['--yes' => true, '--no-interaction' => true]);

    return [$code, Artisan::output()];
}

function base(array $extra = []): array
{
    return $extra + [
        '--name' => 'Construtora Horizonte Demo', '--slug' => 'horizonte-demo',
        '--admin-email' => 'admin@horizonte.test', '--admin-name' => 'Admin Horizonte', '--generate-password' => true,
    ];
}

// ---------------------------------------------------------------- provisionamento
it('cria tenant, administrador, papéis de sistema e vínculo, audita tudo e o admin consegue entrar', function () {
    [$code, $out] = provision(base());

    expect($code)->toBe(0)->and($out)->toContain('Tenant "Construtora Horizonte Demo" criado');

    $tenant = Tenant::query()->where('slug', 'horizonte-demo')->firstOrFail();
    $admin = User::query()->where('email', 'admin@horizonte.test')->firstOrFail();

    expect($tenant->status->value)->toBe('active')
        ->and(Role::query()->forTenant($tenant->id)->orderBy('name')->pluck('name')->all())->toBe(['admin', 'member', 'viewer'])
        ->and(Role::query()->forTenant($tenant->id)->where('is_system', false)->count())->toBe(0)
        ->and(TenantUser::query()->where('tenant_id', $tenant->id)->where('user_id', $admin->id)->value('membership_status')->value)->toBe('active')
        ->and(DB::table('permissions')->count())->toBe(count(PermissionCatalog::PERMISSIONS));

    $papel = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('model_id', $admin->id)->where('model_has_roles.tenant_id', $tenant->id)->pluck('roles.name')->all();
    expect($papel)->toBe(['admin']);

    $eventos = AuditLog::query()->where('tenant_id', $tenant->id)->pluck('event')->all();
    expect($eventos)->toContain(AuditEvent::TENANT_PROVISIONED, AuditEvent::MEMBERSHIP_CREATED, AuditEvent::ROLE_CREATED, AuditEvent::ROLE_ASSIGNED, AuditEvent::PERMISSION_GRANTED)
        ->and(collect($eventos)->filter(fn ($e) => $e === AuditEvent::ROLE_CREATED)->count())->toBe(3);

    // a senha gerada (única exibição) permite o login
    $senha = collect(explode("\n", trim($out)))->last();
    (new Browser($this))->login('admin@horizonte.test', trim($senha))->assertOk()->assertJsonPath('active_tenant.slug', 'horizonte-demo');

    // a senha gerada nunca é gravada na auditoria
    expect(json_encode(AuditLog::query()->get()->toArray()))->not->toContain(trim($senha));
});

it('é idempotente contra execução repetida: o mesmo slug é recusado e nada novo é criado', function () {
    provision(base());
    $antes = [Tenant::query()->count(), User::query()->count(), DB::table('roles')->count(), AuditLog::query()->count()];

    [$code, $out] = provision(base());

    expect($code)->toBe(1)->and($out)->toContain('Já existe um tenant com o slug')
        ->and([Tenant::query()->count(), User::query()->count(), DB::table('roles')->count(), AuditLog::query()->count()])->toBe($antes);
});

it('recusa nome duplicado (salvo --allow-duplicate-name) e slug de tenant removido', function () {
    provision(base());

    [$code, $out] = provision(base(['--slug' => 'outro-slug', '--admin-email' => 'outro@horizonte.test']));
    expect($code)->toBe(1)->and($out)->toContain('Já existe um tenant chamado');

    [$code] = provision(base(['--slug' => 'outro-slug', '--admin-email' => 'outro@horizonte.test', '--allow-duplicate-name' => true]));
    expect($code)->toBe(0);

    Tenant::query()->where('slug', 'outro-slug')->firstOrFail()->delete(); // soft delete: o slug continua reservado
    [$code, $out] = provision(base(['--name' => 'Outro Nome', '--slug' => 'outro-slug', '--admin-email' => 'terceiro@horizonte.test']));
    expect($code)->toBe(1)->and($out)->toContain('inclusive removido');
});

it('administrador duplicado: recusa usuário existente sem --existing-admin; com a flag vincula SEM duplicar a conta', function () {
    provision(base());
    $antes = User::query()->count();

    [$code, $out] = provision(base(['--name' => 'Segunda Obra Demo', '--slug' => 'segunda-obra']));
    expect($code)->toBe(1)->and($out)->toContain('--existing-admin')->and(Tenant::query()->where('slug', 'segunda-obra')->exists())->toBeFalse();

    [$code, $out] = provision(base(['--name' => 'Segunda Obra Demo', '--slug' => 'segunda-obra', '--existing-admin' => true, '--generate-password' => false]));
    expect($code)->toBe(0)->and(User::query()->count())->toBe($antes);

    $admin = User::query()->where('email', 'admin@horizonte.test')->firstOrFail();
    expect(TenantUser::query()->where('user_id', $admin->id)->count())->toBe(2); // admin dos dois tenants, uma só conta
});

it('recusa usuário existente bloqueado e dados inválidos, sem criar nada', function () {
    User::factory()->blocked()->create(['email' => 'bloq@horizonte.test']);

    [$c1, $o1] = provision(base(['--admin-email' => 'bloq@horizonte.test', '--existing-admin' => true, '--generate-password' => false]));
    [$c2, $o2] = provision(base(['--slug' => 'Slug Inválido!']));
    [$c3, $o3] = provision(base(['--admin-email' => 'nao-e-email']));

    expect([$c1, $c2, $c3])->toBe([1, 1, 1])
        ->and($o1)->toContain('bloqueado')->and($o2)->toContain('inválidos')->and($o3)->toContain('inválidos')
        ->and(Tenant::query()->count())->toBe(0);
});

it('é atômico: falha no meio não deixa tenant, usuário nem papéis pela metade', function () {
    // força falha na criação do papel (nome de permissão do catálogo inexistente é impossível); simulamos
    // derrubando a tabela de papéis de sistema via trigger de erro NÃO é permitido — usamos um listener.
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'insert into `model_has_roles`')) {
            throw new RuntimeException('falha simulada no meio do provisionamento');
        }
    });

    expect(fn () => app(ProvisionTenant::class)->run('Atômico Demo', 'atomico-demo', 'a@atomico.test', 'A', null))
        ->toThrow(RuntimeException::class);

    expect(Tenant::query()->withTrashed()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(DB::table('roles')->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- proteções do comando
it('o comando NUNCA aceita senha por argumento', function () {
    expect(fn () => Artisan::call('obraflow:provision', base(['--password' => 'Secreta-Demais-123'])))
        ->toThrow(ConsoleException::class);
});

it('sem prompt e sem --generate-password o comando falha (a senha não vem de argumento)', function () {
    [$code, $out] = provision(base(['--generate-password' => false]));

    expect($code)->toBe(1)->and($out)->toContain('--generate-password')->and(Tenant::query()->count())->toBe(0);
});

it('em produção exige --force', function () {
    app()->detectEnvironment(fn () => 'production');

    [$code] = provision(base());
    expect($code)->toBe(1)->and(Tenant::query()->count())->toBe(0);

    [$code] = provision(base(['--force' => true]));
    expect($code)->toBe(0)->and(Tenant::query()->count())->toBe(1);
});

it('fluxo interativo: senha por prompt oculto com confirmação', function () {
    $this->artisan('obraflow:provision', ['--name' => 'Interativa Demo', '--admin-email' => 'i@interativa.test', '--admin-name' => 'Admin I', '--yes' => true])
        ->expectsQuestion('Senha do administrador (mín. 12, com maiúscula, minúscula e número)', 'Interativa-Senha-123')
        ->expectsQuestion('Repita a senha', 'Interativa-Senha-123')
        ->assertExitCode(0);

    (new Browser($this))->login('i@interativa.test', 'Interativa-Senha-123')->assertOk();
});

it('senhas diferentes ou fracas no prompt não criam nada', function () {
    $this->artisan('obraflow:provision', ['--name' => 'Interativa Demo', '--admin-email' => 'i@interativa.test', '--admin-name' => 'Admin I', '--yes' => true])
        ->expectsQuestion('Senha do administrador (mín. 12, com maiúscula, minúscula e número)', 'Interativa-Senha-123')
        ->expectsQuestion('Repita a senha', 'Outra-Senha-456')
        ->assertExitCode(1);

    expect(fn () => app(ProvisionTenant::class)->run('Fraca Demo', 'fraca-demo', 'f@fraca.test', 'Fraca Admin', 'curta'))
        ->toThrow(ProvisioningException::class, 'Senha fraca');

    expect(Tenant::query()->count())->toBe(0);
});

it('provisionar não cria interface de superadmin: nenhuma rota de plataforma existe', function () {
    $rotas = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri())->filter(fn ($u) => str_contains($u, 'admin') || str_contains($u, 'platform') || str_contains($u, 'provision'));

    expect($rotas)->toBeEmpty();
});

// ---------------------------------------------------------------- comandos operacionais
it('bloquear usuário: recusa o último administrador, revoga tokens e encerra o acesso', function () {
    Scenario::catalog();
    $tenant = Scenario::tenant();
    $unico = Scenario::member($tenant, 'admin');

    expect(Artisan::call('obraflow:user:block', ['email' => $unico->email]))->toBe(1);
    expect($unico->fresh()->isActive())->toBeTrue();

    $segundo = Scenario::member($tenant, 'admin');
    $t = $segundo->createToken('x');
    $t->accessToken->forceFill(['tenant_id' => $tenant->id])->save();
    $browser = new Browser($this);
    $browser->login($segundo->email)->assertOk();

    expect(Artisan::call('obraflow:user:block', ['email' => $segundo->email]))->toBe(0);

    expect($segundo->fresh()->isActive())->toBeFalse()->and(PersonalAccessToken::query()->count())->toBe(0);
    $browser->get('/api/v1/tenant')->assertForbidden()->assertJsonPath('error.code', 'account_blocked');
    (new Browser($this))->login($segundo->email)->assertForbidden();

    expect(AuditLog::query()->where('event', AuditEvent::AUTH_USER_BLOCKED)->count())->toBe(1);

    expect(Artisan::call('obraflow:user:unblock', ['email' => $segundo->email]))->toBe(0);
    expect($segundo->fresh()->isActive())->toBeTrue()->and(AuditLog::query()->where('event', AuditEvent::AUTH_USER_UNBLOCKED)->count())->toBe(1);
});

it('--force bloqueia mesmo o último administrador', function () {
    Scenario::catalog();
    $tenant = Scenario::tenant();
    $unico = Scenario::member($tenant, 'admin');

    expect(Artisan::call('obraflow:user:block', ['email' => $unico->email, '--force' => true]))->toBe(0)
        ->and($unico->fresh()->isActive())->toBeFalse();
});

it('status do tenant: suspender bloqueia o acesso na hora e reativar restaura (ambos auditados)', function () {
    Scenario::catalog();
    $tenant = Scenario::tenant();
    $admin = Scenario::member($tenant, 'admin');
    $browser = new Browser($this);
    $browser->login($admin->email)->assertOk();
    $browser->get('/api/v1/tenant')->assertOk();

    expect(Artisan::call('obraflow:tenant:status', ['slug' => $tenant->slug, 'status' => 'suspended']))->toBe(0);
    $browser->get('/api/v1/tenant')->assertForbidden()->assertJsonPath('error.code', 'tenant_suspended');

    expect(Artisan::call('obraflow:tenant:status', ['slug' => $tenant->slug, 'status' => 'active']))->toBe(0);
    $browser->get('/api/v1/tenant')->assertOk();

    expect(AuditLog::query()->where('tenant_id', $tenant->id)->whereIn('event', [AuditEvent::TENANT_SUSPENDED, AuditEvent::TENANT_REACTIVATED])->pluck('event')->all())
        ->toBe([AuditEvent::TENANT_SUSPENDED, AuditEvent::TENANT_REACTIVATED]);

    expect(Artisan::call('obraflow:tenant:status', ['slug' => $tenant->slug, 'status' => 'trial']))->toBe(1); // status inválido
});

it('obraflow:sync-permissions é idempotente', function () {
    expect(Artisan::call('obraflow:sync-permissions'))->toBe(0);
    $n = DB::table('permissions')->count();

    expect(Artisan::call('obraflow:sync-permissions'))->toBe(0)->and(DB::table('permissions')->count())->toBe($n);
});
