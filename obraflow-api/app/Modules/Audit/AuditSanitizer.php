<?php

namespace App\Modules\Audit;

/**
 * Remove segredos ANTES de gravar na trilha: senha, token, hash, cookie, códigos de recuperação/MFA.
 * Defesa em profundidade: além das chaves, também mascara VALORES com cara de token (Bearer / formato Sanctum).
 */
final class AuditSanitizer
{
    public const REDACTED = '[REDACTED]';

    private const EXACT = [
        'token', 'token_hash', 'remember_token', 'access_token', 'refresh_token', 'api_key', 'apikey',
        'cookie', 'otp', 'mfa_code', 'recovery_codes', 'session_id',
    ];

    private const CONTAINS = ['password', 'passwd', 'secret', 'authorization', 'credential', 'plain_text', 'private_key'];

    /** @param  array<mixed>|null  $data */
    public function sanitize(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->sensitiveKey($key)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => $this->sanitize($value),
                is_string($value) => $this->looksLikeSecret($value) ? self::REDACTED : $value,
                default => $value,
            };
        }

        return $clean;
    }

    public function sensitiveKey(string $key): bool
    {
        $k = strtolower($key);

        if (in_array($k, self::EXACT, true) || str_ends_with($k, '_token') || str_ends_with($k, '_hash')) {
            return true;
        }

        foreach (self::CONTAINS as $needle) {
            if (str_contains($k, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeSecret(string $value): bool
    {
        // "Bearer xxx" ou token do Sanctum no formato "id|40+ caracteres"
        return (bool) preg_match('/^Bearer\s+\S+/i', $value)
            || (bool) preg_match('/^\d+\|[A-Za-z0-9_\-]{30,}$/', $value);
    }
}
