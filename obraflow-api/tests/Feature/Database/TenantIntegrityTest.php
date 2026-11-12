<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\Fx;

beforeEach(function () {
    $this->A = Fx::tenant();
    $this->B = Fx::tenant();
    $this->u1 = Fx::user();   // só em A
    $this->u2 = Fx::user();   // em A e B
    $this->u3 = Fx::user();   // sem vínculo
    Fx::member($this->A, $this->u1);
    Fx::member($this->A, $this->u2);
    Fx::member($this->B, $this->u2);
    $this->roleA = Fx::role($this->A, 'admin');
    $this->roleB = Fx::role($this->B, 'admin');
    $this->viewerB = Fx::role($this->B, 'viewer');
});

// ---------------------------------------------------------------- papéis × tenant × vínculo
it('atribui papel de A a usuário vinculado a A', function () {
    expect(Fx::errno(fn () => Fx::assign($this->A, $this->roleA, $this->u1)))->toBeNull();
});

it('recusa papel do tenant B no vínculo do tenant A (FK composta role)', function () {
    expect(Fx::errno(fn () => Fx::assign($this->A, $this->roleB, $this->u1)))->toBe(1452);
});

it('recusa papel atribuído a usuário SEM vínculo no tenant (FK composta membro)', function () {
    expect(Fx::errno(fn () => Fx::assign($this->A, $this->roleA, $this->u3)))->toBe(1452);
});

it('recusa papel de A atribuído no contexto de B a usuário que só é de A', function () {
    expect(Fx::errno(fn () => Fx::assign($this->B, $this->roleA, $this->u1)))->toBe(1452);
});

it('usuário global: mesma pessoa admin em A e viewer em B', function () {
    Fx::assign($this->A, $this->roleA, $this->u2);
    Fx::assign($this->B, $this->viewerB, $this->u2);

    expect(DB::table('model_has_roles')->where('model_id', $this->u2)->count())->toBe(2);
});

it('recusa model_type diferente de "user" (CHECK)', function () {
    expect(Fx::errno(fn () => Fx::assign($this->A, $this->roleA, $this->u2, 'App\\Models\\User')))->toBe(3819);
});

it('recusa papel sem tenant (NULL)', function () {
    expect(Fx::errno(fn () => DB::table('roles')->insert(['tenant_id' => null, 'name' => 'global', 'guard_name' => 'web'])))->toBe(1048);
});

it('recusa papel duplicado no mesmo tenant, mas aceita o mesmo nome em outro', function () {
    expect(Fx::errno(fn () => Fx::role($this->A, 'admin')))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::role($this->B, 'novo')))->toBeNull()
        ->and(Fx::errno(fn () => Fx::role($this->A, 'novo')))->toBeNull();
});

it('exclusões em uso são recusadas (RESTRICT): papel, vínculo, tenant e permissão', function () {
    Fx::assign($this->A, $this->roleA, $this->u1);
    $perm = DB::table('permissions')->insertGetId(['name' => 'x.y', 'guard_name' => 'web']);
    DB::table('role_has_permissions')->insert(['permission_id' => $perm, 'role_id' => $this->roleA]);

    expect(Fx::errno(fn () => DB::table('roles')->where('id', $this->roleA)->delete()))->toBe(1451)
        ->and(Fx::errno(fn () => DB::table('tenant_users')->where(['tenant_id' => $this->A, 'user_id' => $this->u1])->delete()))->toBe(1451)
        ->and(Fx::errno(fn () => DB::table('tenants')->where('id', $this->A)->delete()))->toBe(1451)
        ->and(Fx::errno(fn () => DB::table('permissions')->where('id', $perm)->delete()))->toBe(1451);
});

it('permissão direta a usuário sem vínculo é recusada; com vínculo, aceita pelo banco', function () {
    $perm = DB::table('permissions')->insertGetId(['name' => 'p.direct', 'guard_name' => 'web']);

    expect(Fx::errno(fn () => DB::table('model_has_permissions')->insert(['permission_id' => $perm, 'model_type' => 'user', 'model_id' => $this->u3, 'tenant_id' => $this->A])))->toBe(1452)
        ->and(Fx::errno(fn () => DB::table('model_has_permissions')->insert(['permission_id' => $perm, 'model_type' => 'user', 'model_id' => $this->u1, 'tenant_id' => $this->A])))->toBeNull();
});

// ---------------------------------------------------------------- vínculos, usuários e tenants
it('vínculo: duplicidade, status inválido e usuário inexistente são recusados', function () {
    expect(Fx::errno(fn () => Fx::member($this->A, $this->u1)))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::member($this->B, $this->u1, 'invited')))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::member($this->A, 999999)))->toBe(1452);
});

it('e-mail único ignorando caixa, mas acentos diferenciam', function () {
    Fx::user('ana@acme.test');

    expect(Fx::errno(fn () => Fx::user('ANA@ACME.TEST')))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::user('jose@x.test')))->toBeNull()
        ->and(Fx::errno(fn () => Fx::user('josé@x.test')))->toBeNull();
});

it('status inválido de usuário e de tenant é recusado; slug é único', function () {
    expect(Fx::errno(fn () => Fx::user(null, 'zzz')))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::tenant(null, 'deleted')))->toBe(3819);

    Fx::tenant('acme');
    expect(Fx::errno(fn () => Fx::tenant('acme')))->toBe(1062);
});

// ---------------------------------------------------------------- domínios e configurações
it('domínios: um único primário por tenant; vários não primários; domínio único global', function () {
    $dom = fn (int $t, string $d, int $p) => DB::table('tenant_domains')->insert(['tenant_id' => $t, 'domain' => $d, 'is_primary' => $p]);

    expect(Fx::errno(fn () => $dom($this->A, 'acme.test', 1)))->toBeNull()
        ->and(Fx::errno(fn () => $dom($this->A, 'acme2.test', 1)))->toBe(1062)
        ->and(Fx::errno(fn () => $dom($this->A, 'a1.test', 0)))->toBeNull()
        ->and(Fx::errno(fn () => $dom($this->A, 'a2.test', 0)))->toBeNull()
        ->and(Fx::errno(fn () => $dom($this->B, 'beta.test', 1)))->toBeNull()
        ->and(Fx::errno(fn () => $dom($this->B, 'a1.test', 0)))->toBe(1062);
});

it('configurações: única por (tenant, chave)', function () {
    $set = fn (int $t, string $k) => DB::table('tenant_settings')->insert(['tenant_id' => $t, 'setting_key' => $k, 'setting_value' => '"v"']);

    expect(Fx::errno(fn () => $set($this->A, 'currency')))->toBeNull()
        ->and(Fx::errno(fn () => $set($this->A, 'currency')))->toBe(1062)
        ->and(Fx::errno(fn () => $set($this->B, 'currency')))->toBeNull();
});
