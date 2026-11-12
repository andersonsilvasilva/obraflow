<?php

namespace App\Modules\Access\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Papéis do tenant (D-18: nunca globais). Papéis de sistema são imutáveis. Mudanças de permissões são auditadas
 * como permission.granted / permission.revoked (com o diff) na mesma transação.
 */
final class RoleService
{
    public function __construct(
        private readonly TenantLock $lock,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly PrivilegeGuard $privileges,
    ) {}

    /** @param  list<string>  $permissions */
    public function create(User $actor, Tenant $tenant, string $name, array $permissions): Role
    {
        $permissions = array_values(array_unique($permissions));
        $this->privileges->assertCanGrantPermissions($actor, $tenant, $permissions);

        return $this->lock->run($tenant, function () use ($actor, $tenant, $name, $permissions): Role {
            return $this->context->run($tenant, function () use ($actor, $tenant, $name, $permissions): Role {
                /** @var Role $role */
                $role = Role::create(['name' => $name, 'guard_name' => 'web', 'tenant_id' => $tenant->getKey()]);
                $role->syncPermissions($permissions);

                $this->audit->record(AuditEvent::ROLE_CREATED, tenantId: $tenant->getKey(), userId: $actor->getKey(),
                    entityType: 'role', entityId: $role->getKey(), new: ['name' => $name, 'permissions' => $permissions]);

                if ($permissions !== []) {
                    $this->audit->record(AuditEvent::PERMISSION_GRANTED, tenantId: $tenant->getKey(), userId: $actor->getKey(),
                        entityType: 'role', entityId: $role->getKey(), new: ['role' => $name, 'permissions' => $permissions]);
                }

                return $role;
            });
        });
    }

    /**
     * @param  list<string>|null  $permissions  null = não altera
     */
    public function update(User $actor, Tenant $tenant, Role $role, ?string $name, ?array $permissions): Role
    {
        $this->assertOwned($tenant, $role);

        if ($role->is_system) {
            throw ApiException::conflict('system_role_immutable', 'Papéis de sistema não podem ser alterados.');
        }
        if ($permissions !== null) {
            $permissions = array_values(array_unique($permissions));
            $this->privileges->assertCanGrantPermissions($actor, $tenant, $permissions);
        }

        return $this->lock->run($tenant, function () use ($actor, $tenant, $role, $name, $permissions): Role {
            return $this->context->run($tenant, function () use ($actor, $tenant, $role, $name, $permissions): Role {
                $role = Role::query()->forTenant($tenant->getKey())->findOrFail($role->getKey());
                $oldName = $role->name;

                if ($name !== null && $name !== $oldName) {
                    $role->forceFill(['name' => $name])->save();
                    $this->audit->record(AuditEvent::ROLE_UPDATED, tenantId: $tenant->getKey(), userId: $actor->getKey(),
                        entityType: 'role', entityId: $role->getKey(), old: ['name' => $oldName], new: ['name' => $name]);
                }

                if ($permissions !== null) {
                    $before = $role->permissions()->pluck('name')->all();
                    $granted = array_values(array_diff($permissions, $before));
                    $revoked = array_values(array_diff($before, $permissions));

                    $role->syncPermissions($permissions);

                    if ($granted !== []) {
                        $this->audit->record(AuditEvent::PERMISSION_GRANTED, tenantId: $tenant->getKey(), userId: $actor->getKey(),
                            entityType: 'role', entityId: $role->getKey(), new: ['role' => $role->name, 'permissions' => $granted]);
                    }
                    if ($revoked !== []) {
                        $this->audit->record(AuditEvent::PERMISSION_REVOKED, tenantId: $tenant->getKey(), userId: $actor->getKey(),
                            entityType: 'role', entityId: $role->getKey(), old: ['role' => $role->name, 'permissions' => $revoked]);
                    }
                }

                return $role->refresh();
            });
        });
    }

    public function delete(User $actor, Tenant $tenant, Role $role): void
    {
        $this->assertOwned($tenant, $role);

        if ($role->is_system) {
            throw ApiException::conflict('system_role_immutable', 'Papéis de sistema não podem ser excluídos.');
        }

        $this->lock->run($tenant, function () use ($actor, $tenant, $role): void {
            $this->context->run($tenant, function () use ($actor, $tenant, $role): void {
                $inUse = DB::table('model_has_roles')->where('tenant_id', $tenant->getKey())->where('role_id', $role->getKey())->exists()
                    || DB::table('user_invitations')->where('tenant_id', $tenant->getKey())->where('role_id', $role->getKey())->exists();

                if ($inUse) {
                    throw ApiException::conflict('role_in_use', 'Este papel está atribuído a usuários ou convites. Remova-os antes de excluí-lo.');
                }

                $permissions = $role->permissions()->pluck('name')->all();
                $role->syncPermissions([]);

                if ($permissions !== []) {
                    $this->audit->record(AuditEvent::PERMISSION_REVOKED, tenantId: $tenant->getKey(), userId: $actor->getKey(),
                        entityType: 'role', entityId: $role->getKey(), old: ['role' => $role->name, 'permissions' => $permissions]);
                }

                try {
                    $role->delete();
                } catch (QueryException) {
                    throw ApiException::conflict('role_in_use', 'Este papel ainda está em uso.');
                }

                $this->audit->record(AuditEvent::ROLE_DELETED, tenantId: $tenant->getKey(), userId: $actor->getKey(),
                    entityType: 'role', entityId: $role->getKey(), old: ['name' => $role->name, 'permissions' => $permissions]);
            });
        });
    }

    private function assertOwned(Tenant $tenant, Role $role): void
    {
        if ((int) $role->tenant_id !== (int) $tenant->getKey()) {
            throw ApiException::notFound('Papel não encontrado.');
        }
    }
}
