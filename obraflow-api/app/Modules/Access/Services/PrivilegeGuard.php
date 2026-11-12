<?php

namespace App\Modules\Access\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\TenantContext;

/**
 * Anti-escalação de privilégio: ninguém concede permissão (nem atribui/convida com papel) que o próprio autor
 * não possua NO TENANT. Evita que um gestor de papéis delegado se torne administrador.
 */
final class PrivilegeGuard
{
    public function __construct(private readonly TenantContext $context) {}

    /** @param  list<string>  $permissions */
    public function assertCanGrantPermissions(User $actor, Tenant $tenant, array $permissions): void
    {
        $missing = $this->context->run($tenant, function () use ($actor, $permissions): array {
            return array_values(array_diff($permissions, $actor->getAllPermissions()->pluck('name')->all()));
        }, $actor);

        if ($missing !== []) {
            throw new ApiException(403, 'privilege_escalation', 'Você não pode conceder permissões que você mesmo não possui.', ['missing_permissions' => $missing]);
        }
    }

    public function assertCanGrantRole(User $actor, Tenant $tenant, Role $role): void
    {
        $this->assertCanGrantPermissions($actor, $tenant, $role->permissions()->pluck('name')->all());
    }
}
