<?php

namespace App\Modules\Tenancy;

use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Shared\Exceptions\ApiException;
use Illuminate\Http\Request;

/**
 * Determina e VALIDA o tenant ativo (D-P01). Fontes de autoridade, e SÓ elas:
 *   - token Sanctum  → personal_access_tokens.tenant_id (imutável, definido na emissão);
 *   - SPA (cookie)   → tenant gravado na SESSÃO DO SERVIDOR pela seleção explícita.
 * Nenhum header, parâmetro ou corpo do cliente é autoridade (inclui X-Tenant-Hint, que só é comparado).
 *
 * É executado em TODA requisição de negócio: suspensão/remoção do vínculo vale na próxima requisição.
 */
final class ActiveTenantResolver
{
    public const SESSION_KEY = 'active_tenant_id';

    public function isTokenRequest(User $user): bool
    {
        return $user->currentAccessToken() instanceof PersonalAccessToken;
    }

    /** @return array{0: Tenant, 1: TenantUser} */
    public function resolve(Request $request, User $user): array
    {
        $token = $user->currentAccessToken();
        $isToken = $token instanceof PersonalAccessToken;

        $tenantId = $isToken
            ? $token->tenant_id
            : ($request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null);

        if (! $tenantId) {
            throw ApiException::forbidden(
                'tenant_required',
                $isToken
                    ? 'Este token não está vinculado a um tenant e não acessa dados de negócio.'
                    : 'Selecione o tenant ativo para continuar.',
            );
        }

        if (! $user->isActive()) {
            throw ApiException::forbidden('account_blocked', 'Esta conta está bloqueada.');
        }

        $tenant = Tenant::query()->find($tenantId); // exclui tenants removidos (soft delete)
        $membership = $tenant
            ? TenantUser::query()->where('tenant_id', $tenant->getKey())->where('user_id', $user->getKey())->first()
            : null;

        if (! $tenant || ! $membership || ! $membership->isActive()) {
            if (! $isToken && $request->hasSession()) {
                $request->session()->forget(self::SESSION_KEY); // não insistir num tenant sem acesso
            }

            throw ApiException::forbidden('tenant_forbidden', 'Você não tem acesso ativo a este tenant.');
        }

        if (! $tenant->allowsAccess()) {
            throw ApiException::forbidden('tenant_suspended', 'Este tenant está suspenso ou cancelado.');
        }

        return [$tenant, $membership];
    }
}
