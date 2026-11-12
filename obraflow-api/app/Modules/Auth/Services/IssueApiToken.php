<?php

namespace App\Modules\Auth\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Shared\Exceptions\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Emissão de token de API FIXADO no tenant (D-05/D-20). O Sanctum não conhece tenant, então o tenant_id é
 * gravado na MESMA transação da criação. O banco ainda garante que o usuário tem vínculo (FK composta).
 * Expiração obrigatória e limitada a `obraflow.api_token_max_days`. O valor em claro é devolvido UMA vez.
 */
final class IssueApiToken
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  list<string>  $abilities */
    public function issue(User $user, Tenant $tenant, string $name, int $expiresInDays, array $abilities = ['*']): NewAccessToken
    {
        $max = (int) config('obraflow.api_token_max_days');

        if ($expiresInDays < 1 || $expiresInDays > $max) {
            throw ApiException::unprocessable('invalid_token_expiration', "A validade do token deve ficar entre 1 e {$max} dias.");
        }

        try {
            return DB::transaction(function () use ($user, $tenant, $name, $expiresInDays, $abilities): NewAccessToken {
                $new = $user->createToken($name, $abilities, now()->addDays($expiresInDays));
                $new->accessToken->forceFill(['tenant_id' => $tenant->getKey()])->save();

                $this->audit->record(
                    AuditEvent::TOKEN_CREATED,
                    tenantId: $tenant->getKey(),
                    userId: $user->getKey(),
                    entityType: 'token',
                    entityId: $new->accessToken->getKey(),
                    metadata: [
                        'token_id' => $new->accessToken->getKey(),
                        'name' => $name,
                        'abilities' => $abilities,
                        'expires_at' => $new->accessToken->expires_at?->toIso8601String(),
                    ],
                );

                return $new;
            });
        } catch (QueryException $e) {
            // FK composta: usuário sem vínculo no tenant
            throw ApiException::forbidden('tenant_forbidden', 'Você não tem vínculo ativo com este tenant.');
        }
    }
}
