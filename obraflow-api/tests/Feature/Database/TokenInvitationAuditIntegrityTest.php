<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Fx;

beforeEach(function () {
    $this->A = Fx::tenant();
    $this->B = Fx::tenant();
    $this->u1 = Fx::user();
    $this->u2 = Fx::user();
    $this->u3 = Fx::user();
    Fx::member($this->A, $this->u1);
    Fx::member($this->A, $this->u2);
    Fx::member($this->B, $this->u2);
    $this->roleA = Fx::role($this->A, 'admin');
    $this->roleB = Fx::role($this->B, 'admin');
});

// ---------------------------------------------------------------- tokens
it('token com tenant só para usuário vinculado', function () {
    expect(Fx::errno(fn () => Fx::token($this->A, $this->u1)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::token($this->A, $this->u3)))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::token($this->B, $this->u1)))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::token(999999, $this->u1)))->toBe(1452);
});

it('token sem tenant é aceito pelo banco (a barreira é do middleware) e tokenable_type ≠ user é recusado', function () {
    expect(Fx::errno(fn () => Fx::token(null, $this->u3)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::token($this->A, $this->u1, 'App\\Models\\User')))->toBe(3819);
});

// ---------------------------------------------------------------- convites
it('convite válido; convidante fora do tenant e papel de outro tenant são recusados', function () {
    expect(Fx::errno(fn () => Fx::invitation($this->A, 'novo@x.test', $this->u1, $this->roleA)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::invitation($this->A, 'n2@x.test', $this->u3, $this->roleA)))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::invitation($this->A, 'n3@x.test', $this->u1, $this->roleB)))->toBe(1452);
});

it('apenas um convite pendente por e-mail/tenant (inclusive com outra caixa)', function () {
    Fx::invitation($this->A, 'novo@x.test', $this->u1, $this->roleA);

    expect(Fx::errno(fn () => Fx::invitation($this->A, 'novo@x.test', $this->u1, $this->roleA)))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::invitation($this->A, 'NOVO@X.TEST', $this->u1, $this->roleA)))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::invitation($this->B, 'novo@x.test', $this->u2, $this->roleB)))->toBeNull();
});

it('convite revogado não bloqueia novo convite do mesmo e-mail', function () {
    Fx::invitation($this->A, 'rev@x.test', $this->u1, $this->roleA, 'revoked');

    expect(Fx::errno(fn () => Fx::invitation($this->A, 'rev@x.test', $this->u1, $this->roleA)))->toBeNull();
});

it('convite aceito exige accepted_at e accepted_user_id, e o aceitante precisa ter vínculo', function () {
    expect(Fx::errno(fn () => Fx::invitation($this->A, 'acc@x.test', $this->u1, $this->roleA, 'accepted')))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::invitation($this->A, 'acc2@x.test', $this->u1, $this->roleA, 'accepted', $this->u2, true)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::invitation($this->A, 'acc3@x.test', $this->u1, $this->roleA, 'accepted', $this->u3, true)))->toBe(1452);
});

it('token_hash do convite é único', function () {
    $hash = bin2hex(random_bytes(32));
    $ins = fn (string $email) => DB::table('user_invitations')->insert([
        'tenant_id' => $this->A, 'email' => $email, 'invited_by' => $this->u1, 'role_id' => $this->roleA,
        'token_hash' => $hash, 'expires_at' => '2030-01-01 00:00:00',
    ]);

    expect(Fx::errno(fn () => $ins('d1@x.test')))->toBeNull()
        ->and(Fx::errno(fn () => $ins('d2@x.test')))->toBe(1062);
});

// ---------------------------------------------------------------- auditoria
it('audit_logs aceita evento fora de tenant e com tenant; recusa tenant inexistente', function () {
    $log = fn (?int $tenant, ?int $user = null) => DB::table('audit_logs')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'user_id' => $user, 'event' => 'auth.login']);

    expect(Fx::errno(fn () => $log(null)))->toBeNull()
        ->and(Fx::errno(fn () => $log($this->A, $this->u1)))->toBeNull()
        ->and(Fx::errno(fn () => $log(999999)))->toBe(1452);
});

// ---------------------------------------------------------------- UTC (#17)
it('a sessão MySQL está em UTC e o round-trip de datas é exato', function () {
    expect(DB::selectOne('SELECT @@session.time_zone AS tz')->tz)->toBe('+00:00');

    DB::table('tenant_settings')->insert([
        'tenant_id' => $this->A, 'setting_key' => 'tz_probe', 'setting_value' => '1', 'created_at' => '2026-01-01 12:00:00',
    ]);

    $lido = DB::table('tenant_settings')->where('setting_key', 'tz_probe')->value('created_at');
    expect($lido)->toBe('2026-01-01 12:00:00');
});

it('prova do risco: uma sessão fora de UTC deslocaria o TIMESTAMP (por isso a conexão é forçada a +00:00)', function () {
    DB::statement("SET time_zone = '-04:00'");
    DB::table('tenant_settings')->insert([
        'tenant_id' => $this->A, 'setting_key' => 'tz_shift', 'setting_value' => '1', 'created_at' => '2026-01-01 12:00:00',
    ]);
    DB::statement("SET time_zone = '+00:00'");

    $lido = DB::table('tenant_settings')->where('setting_key', 'tz_shift')->value('created_at');
    expect($lido)->toBe('2026-01-01 16:00:00');
});
