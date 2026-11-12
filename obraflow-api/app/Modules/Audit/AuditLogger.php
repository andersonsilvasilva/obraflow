<?php

namespace App\Modules\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gravação da trilha de auditoria.
 *
 *  - record():           LANÇA em caso de falha. Use para eventos de AUTORIZAÇÃO dentro de DB::transaction:
 *                        se a auditoria falhar, a operação inteira é revertida (falha fechada).
 *  - recordBestEffort(): NUNCA lança (log de erro). Use para auth.* — uma falha de auditoria não pode
 *                        derrubar o login.
 *
 * Os dados são sanitizados (AuditSanitizer) antes de qualquer gravação.
 */
final class AuditLogger
{
    public function __construct(private readonly AuditSanitizer $sanitizer) {}

    /**
     * @param  array<mixed>|null  $old
     * @param  array<mixed>|null  $new
     * @param  array<mixed>|null  $metadata
     */
    public function record(
        string $event,
        ?int $tenantId = null,
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $old = null,
        ?array $new = null,
        ?array $metadata = null,
    ): AuditLog {
        $request = app()->bound('request') ? request() : null;
        $ip = $request?->ip();

        return AuditLog::query()->forceCreate([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'event' => $event,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $this->sanitizer->sanitize($old),
            'new_values' => $this->sanitizer->sanitize($new),
            'metadata' => $this->sanitizer->sanitize($metadata),
            'ip_address' => $ip ? @inet_pton($ip) ?: null : null,
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) ?: null : null,
            'request_id' => $request?->attributes->get('request_id'),
        ]);
    }

    /**
     * @param  array<mixed>|null  $old
     * @param  array<mixed>|null  $new
     * @param  array<mixed>|null  $metadata
     */
    public function recordBestEffort(
        string $event,
        ?int $tenantId = null,
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $old = null,
        ?array $new = null,
        ?array $metadata = null,
    ): ?AuditLog {
        try {
            return $this->record($event, $tenantId, $userId, $entityType, $entityId, $old, $new, $metadata);
        } catch (Throwable $e) {
            // Nunca registrar o conteúdo do evento no log de erro (poderia conter dados sensíveis).
            Log::error('Falha ao gravar auditoria (melhor esforço)', ['event' => $event, 'exception' => $e::class]);

            return null;
        }
    }

    /** HMAC do e-mail para correlacionar tentativas sem gravar o e-mail em claro. */
    public static function emailHmac(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }
}
