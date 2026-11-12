<?php

use App\Modules\Audit\AuditSanitizer;

beforeEach(fn () => $this->s = new AuditSanitizer);

it('mascara senha, token, hash e demais segredos por NOME da chave', function () {
    $clean = $this->s->sanitize([
        'password' => 'x', 'password_confirmation' => 'x', 'current_password' => 'x', 'new_password' => 'x',
        'token' => 'x', 'token_hash' => 'x', 'remember_token' => 'x', 'access_token' => 'x', 'reset_token' => 'x', 'invite_token' => 'x',
        'plain_text_token' => 'x', 'api_key' => 'x', 'client_secret' => 'x', 'Authorization' => 'x', 'cookie' => 'x',
        'recovery_codes' => ['a', 'b'], 'mfa_code' => '123456', 'otp' => '1', 'password_hash' => 'x', 'private_key' => 'x',
    ]);

    foreach ($clean as $key => $value) {
        expect($value)->toBe(AuditSanitizer::REDACTED, "chave {$key} deveria estar mascarada");
    }
});

it('mascara recursivamente, inclusive dentro de listas', function () {
    $clean = $this->s->sanitize(['user' => ['name' => 'Ana', 'password' => 'x'], 'itens' => [['token' => 'x', 'ok' => 1]]]);

    expect($clean['user'])->toBe(['name' => 'Ana', 'password' => AuditSanitizer::REDACTED])
        ->and($clean['itens'][0])->toBe(['token' => AuditSanitizer::REDACTED, 'ok' => 1]);
});

it('mascara VALORES com cara de token mesmo sob uma chave inocente (defesa em profundidade)', function () {
    $clean = $this->s->sanitize([
        'header' => 'Bearer abcdefghijklmnop',
        'nota' => '12|'.str_repeat('a', 40),
        'comentario' => 'texto normal',
    ]);

    expect($clean['header'])->toBe(AuditSanitizer::REDACTED)
        ->and($clean['nota'])->toBe(AuditSanitizer::REDACTED)
        ->and($clean['comentario'])->toBe('texto normal');
});

it('preserva dados legítimos de auditoria (ids, e-mail HMAC, papéis, status)', function () {
    $in = [
        'token_id' => 7, 'email_hmac' => str_repeat('a', 64), 'role' => 'admin', 'membership_status' => 'active',
        'tokens_revoked' => 3, 'permissions' => ['tenant.view'], 'from_tenant_id' => null, 'expires_at' => '2026-12-01T00:00:00+00:00',
    ];

    expect($this->s->sanitize($in))->toBe($in);
});

it('aceita null e arrays vazios', function () {
    expect($this->s->sanitize(null))->toBeNull()->and($this->s->sanitize([]))->toBe([]);
});
