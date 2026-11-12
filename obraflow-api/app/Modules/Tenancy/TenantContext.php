<?php

namespace App\Modules\Tenancy;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Tenancy\Exceptions\MissingTenantContext;
use Spatie\Permission\PermissionRegistrar;

/**
 * Serviço ÚNICO do tenant ativo. Registrado como `scoped` (uma instância por requisição/job).
 *
 * Responsabilidades:
 *  - guardar o tenant ativo e o vínculo validado;
 *  - sincronizar o contexto do Spatie (setPermissionsTeamId);
 *  - descartar as relações de papéis/permissões em cache do usuário ao trocar de tenant
 *    (armadilha confirmada em FASE1_MODELO §7.1: o mesmo objeto de usuário mantém os papéis do tenant anterior).
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private ?TenantUser $membership = null;

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function membership(): ?TenantUser
    {
        return $this->membership;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    /** @throws MissingTenantContext */
    public function idOrFail(): int
    {
        return $this->tenant?->getKey() ?? throw MissingTenantContext::make();
    }

    /** @throws MissingTenantContext */
    public function tenantOrFail(): Tenant
    {
        return $this->tenant ?? throw MissingTenantContext::make();
    }

    /**
     * Ativa o tenant. SEMPRE use este método (e nunca setPermissionsTeamId diretamente):
     * ele também limpa as relações em cache do usuário informado.
     */
    public function use(Tenant $tenant, ?TenantUser $membership = null, ?User $user = null): void
    {
        $this->tenant = $tenant;
        $this->membership = $membership;
        $this->registrar->setPermissionsTeamId($tenant->getKey());

        $user?->unsetRelation('roles')->unsetRelation('permissions');
    }

    public function forget(?User $user = null): void
    {
        $this->tenant = null;
        $this->membership = null;
        $this->registrar->setPermissionsTeamId(null);

        $user?->unsetRelation('roles')->unsetRelation('permissions');
    }

    /**
     * Executa $callback dentro de um tenant e RESTAURA o contexto anterior (inclusive o do Spatie).
     * Use em jobs, comandos e testes.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, callable $callback, ?User $user = null): mixed
    {
        $previousTenant = $this->tenant;
        $previousMembership = $this->membership;

        $this->use($tenant, null, $user);

        try {
            return $callback();
        } finally {
            if ($previousTenant) {
                $this->use($previousTenant, $previousMembership, $user);
            } else {
                $this->forget($user);
            }
        }
    }
}
