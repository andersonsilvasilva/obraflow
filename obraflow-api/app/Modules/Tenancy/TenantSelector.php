<?php

namespace App\Modules\Tenancy;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Shared\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Seleção/troca EXPLÍCITA do tenant ativo (SPA). Valida o DESTINO, grava na sessão do servidor, REGENERA o
 * identificador da sessão (evita fixação) e audita `tenant.selected` (origem → destino) na mesma transação.
 * Token de API não troca de tenant: é fixo (D-20).
 */
final class TenantSelector
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActiveTenantResolver $resolver,
    ) {}

    /** @return array{0: Tenant, 1: TenantUser} */
    public function select(Request $request, User $user, string $tenantUuid): array
    {
        if ($this->resolver->isTokenRequest($user) || ! $request->hasSession()) {
            throw ApiException::forbidden('tenant_fixed', 'Tokens de API são fixos em um tenant e não podem trocá-lo.');
        }

        $tenant = Tenant::query()->where('uuid', $tenantUuid)->first();
        $membership = $tenant
            ? TenantUser::query()->where('tenant_id', $tenant->getKey())->where('user_id', $user->getKey())->first()
            : null;

        // Mesma resposta para "não existe" e "sem vínculo": evita enumeração de tenants.
        if (! $tenant || ! $membership || ! $membership->isActive()) {
            $this->deny($user, $tenant, 'no_active_membership');
            throw ApiException::forbidden('tenant_forbidden', 'Você não tem acesso ativo a este tenant.');
        }

        if (! $tenant->allowsAccess()) {
            $this->deny($user, $tenant, 'tenant_not_accessible');
            throw ApiException::forbidden('tenant_suspended', 'Este tenant está suspenso ou cancelado.');
        }

        $from = $request->session()->get(ActiveTenantResolver::SESSION_KEY);

        // 1) banco + auditoria numa transação: se a auditoria falhar, nada é aplicado.
        DB::transaction(function () use ($user, $tenant, $membership, $from): void {
            $membership->forceFill(['last_used_at' => now()])->save();

            $this->audit->record(
                AuditEvent::TENANT_SELECTED,
                tenantId: $tenant->getKey(),
                userId: $user->getKey(),
                entityType: 'tenant',
                entityId: $tenant->getKey(),
                metadata: ['from_tenant_id' => $from, 'to_tenant_id' => $tenant->getKey()],
            );
        });

        // 2) a SESSÃO não é transacional: só é alterada DEPOIS do commit, para que a troca nunca valha sem registro.
        $request->session()->put(ActiveTenantResolver::SESSION_KEY, $tenant->getKey());
        $request->session()->regenerate();

        return [$tenant, $membership];
    }

    private function deny(User $user, ?Tenant $tenant, string $reason): void
    {
        $this->audit->recordBestEffort(
            AuditEvent::TENANT_SELECTION_DENIED,
            tenantId: $tenant?->getKey(),
            userId: $user->getKey(),
            entityType: 'tenant',
            entityId: $tenant?->getKey(),
            metadata: ['reason' => $reason],
        );
    }
}
