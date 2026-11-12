<?php

use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Modules\Tenancy\Exceptions\MissingTenantContext;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Concerns;

beforeEach(function () {
    $this->ctx = app(TenantContext::class);
    $this->A = Tenant::factory()->create();
    $this->B = Tenant::factory()->create();
});

// ---------------------------------------------------------------- identidade global
it('users é global: cria com uuid automático, status ativo e sem tenant_id', function () {
    $user = User::factory()->create();

    expect($user->uuid)->toBeString()->toHaveLength(36)
        ->and($user->isActive())->toBeTrue()
        ->and($user->getAttributes())->not->toHaveKey('tenant_id')
        ->and($user->getKey())->toBeInt();
});

it('status do usuário e do tenant não são mass-assignable', function () {
    $user = User::create(['name' => 'X', 'email' => 'x@example.test', 'password' => 'Senha-de-Teste-123', 'status' => 'blocked']);
    $tenant = Tenant::create(['name' => 'T', 'slug' => 'tt-slug', 'status' => 'suspended']);

    expect($user->fresh()->isActive())->toBeTrue()
        ->and($tenant->fresh()->allowsAccess())->toBeTrue();
});

it('um usuário global pode ter vínculo em vários tenants; activeTenants ignora suspensos', function () {
    $user = User::factory()->create();
    Concerns::member($this->A, $user);
    Concerns::member($this->B, $user, MembershipStatus::Suspended);

    expect($user->tenants()->count())->toBe(2)
        ->and($user->activeTenants()->pluck('tenants.id')->all())->toBe([$this->A->id])
        ->and($this->A->users()->count())->toBe(1);
});

// ---------------------------------------------------------------- BelongsToTenant
it('sem contexto de tenant, consultar modelo de tenant FALHA FECHADO (nunca devolve tudo)', function () {
    expect(fn () => TenantSetting::query()->get())->toThrow(MissingTenantContext::class);
});

it('com contexto, o escopo devolve só o tenant ativo', function () {
    $this->ctx->run($this->A, fn () => TenantSetting::create(['setting_key' => 'k', 'setting_value' => ['v' => 1]]));
    $this->ctx->run($this->B, fn () => TenantSetting::create(['setting_key' => 'k', 'setting_value' => ['v' => 2]]));

    $a = $this->ctx->run($this->A, fn () => TenantSetting::query()->get());
    $b = $this->ctx->run($this->B, fn () => TenantSetting::query()->get());

    expect($a)->toHaveCount(1)->and($a->first()->setting_value)->toBe(['v' => 1])
        ->and($b)->toHaveCount(1)->and($b->first()->setting_value)->toBe(['v' => 2]);
});

it('creating usa o tenant do CONTEXTO e ignora tenant_id vindo do cliente', function () {
    $setting = $this->ctx->run($this->A, fn () => TenantSetting::create([
        'setting_key' => 'forjado', 'setting_value' => [], 'tenant_id' => $this->B->id,
    ]));

    expect($setting->tenant_id)->toBe($this->A->id);
});

it('um registro de outro tenant é invisível (find devolve null)', function () {
    $other = $this->ctx->run($this->B, fn () => TenantSetting::create(['setting_key' => 'secreto', 'setting_value' => []]));

    $found = $this->ctx->run($this->A, fn () => TenantSetting::query()->find($other->id));

    expect($found)->toBeNull();
});

it('tenant_id é imutável', function () {
    $setting = $this->ctx->run($this->A, fn () => TenantSetting::create(['setting_key' => 'k', 'setting_value' => []]));
    $setting->tenant_id = $this->B->id;

    expect(fn () => $setting->save())->toThrow(LogicException::class);
});

// ---------------------------------------------------------------- TenantContext
it('use/forget sincronizam o contexto do Spatie', function () {
    $registrar = app(PermissionRegistrar::class);

    $this->ctx->use($this->A);
    expect($registrar->getPermissionsTeamId())->toBe($this->A->id)->and($this->ctx->idOrFail())->toBe($this->A->id);

    $this->ctx->forget();
    expect($registrar->getPermissionsTeamId())->toBeNull()->and($this->ctx->has())->toBeFalse();
});

it('run restaura o contexto anterior (inclusive o do Spatie), mesmo com exceção', function () {
    $registrar = app(PermissionRegistrar::class);
    $this->ctx->use($this->A);

    try {
        $this->ctx->run($this->B, function () use ($registrar) {
            expect($registrar->getPermissionsTeamId())->toBe($this->B->id);
            throw new RuntimeException('falha');
        });
    } catch (RuntimeException) {
    }

    expect($this->ctx->idOrFail())->toBe($this->A->id)->and($registrar->getPermissionsTeamId())->toBe($this->A->id);
});

it('trocar de tenant descarta as relações de papéis em cache do MESMO objeto de usuário (armadilha R14)', function () {
    $user = User::factory()->create();
    Concerns::member($this->A, $user);
    Concerns::member($this->B, $user);
    $admin = Concerns::role($this->A, 'admin');

    $this->ctx->use($this->A, null, $user);
    $user->assignRole($admin);
    expect($user->hasRole('admin'))->toBeTrue();

    // mesma instância do usuário, outro tenant: sem o descarte, ainda seria "admin"
    $this->ctx->use($this->B, null, $user);
    expect($user->hasRole('admin'))->toBeFalse();
});

// ---------------------------------------------------------------- morph map / tokens / papéis
it('enforceMorphMap: o alias de usuário é "user" e classes fora do mapa são recusadas', function () {
    expect((new User)->getMorphClass())->toBe('user')
        ->and(Relation::morphMap())->toHaveKey('user');

    $unmapped = new class extends Model {};
    expect(fn () => $unmapped->getMorphClass())->toThrow(ClassMorphViolationException::class);
});

it('o Sanctum usa o modelo de token do ObraFlow (com tenant)', function () {
    expect(Sanctum::$personalAccessTokenModel)->toBe(PersonalAccessToken::class);
});

it('Role não possui escopo global de tenant (cache global do Spatie) e papel sem contexto falha no banco', function () {
    expect(Role::query()->toSql())->not->toContain('tenant_id');

    $this->ctx->forget();
    expect(fn () => Role::create(['name' => 'sem-tenant', 'guard_name' => 'web']))->toThrow(QueryException::class);
    expect(DB::table('roles')->whereNull('tenant_id')->count())->toBe(0);
});

it('scopeForTenant filtra papéis explicitamente', function () {
    Concerns::role($this->A, 'admin');
    Concerns::role($this->B, 'admin');

    expect(Role::query()->forTenant($this->A->id)->count())->toBe(1);
});

// ---------------------------------------------------------------- auditoria (imutabilidade pelo modelo)
it('AuditLog é append-only para a aplicação: sem update/delete/truncate', function () {
    $log = AuditLog::query()->forceCreate(['event' => 'auth.login']);

    $log->event = 'adulterado';

    expect(fn () => $log->save())->toThrow(LogicException::class)
        ->and(fn () => $log->update(['event' => 'x']))->toThrow(MassAssignmentException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class)
        ->and(fn () => AuditLog::query()->update(['event' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => AuditLog::query()->delete())->toThrow(LogicException::class)
        ->and(fn () => AuditLog::truncate())->toThrow(LogicException::class)
        ->and(AuditLog::query()->count())->toBe(1);
});
