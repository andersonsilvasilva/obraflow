<?php

namespace App\Modules\Access\Services;

use App\Enums\MembershipStatus;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

/**
 * Vínculos usuário↔tenant e atribuição de papéis. Toda mudança:
 *  - roda sob TenantLock (concorrência) e em transação;
 *  - grava a auditoria NA MESMA transação (falha de auditoria desfaz a operação);
 *  - protege o último administrador ativo;
 *  - revoga os tokens do tenant quando o acesso é suspenso/removido.
 */
final class MembershipService
{
    public function __construct(
        private readonly TenantLock $lock,
        private readonly LastAdminGuard $lastAdmin,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly PrivilegeGuard $privileges,
    ) {}

    public function suspend(User $actor, Tenant $tenant, TenantUser $membership): TenantUser
    {
        return $this->lock->run($tenant, function () use ($actor, $tenant, $membership): TenantUser {
            $membership = $this->fresh($membership);

            if ($membership->membership_status !== MembershipStatus::Active) {
                throw ApiException::conflict('invalid_membership_state', 'Só vínculos ativos podem ser suspensos.');
            }

            $this->lastAdmin->assertNotLast($tenant, $membership->user_id, 'suspender este usuário');

            return $this->transition($actor, $tenant, $membership, MembershipStatus::Suspended, AuditEvent::MEMBERSHIP_SUSPENDED, revokeTokens: true);
        });
    }

    public function reactivate(User $actor, Tenant $tenant, TenantUser $membership): TenantUser
    {
        return $this->lock->run($tenant, function () use ($actor, $tenant, $membership): TenantUser {
            $membership = $this->fresh($membership);

            if ($membership->membership_status !== MembershipStatus::Suspended) {
                throw ApiException::conflict('invalid_membership_state', 'Só vínculos suspensos podem ser reativados (vínculos removidos exigem novo convite).');
            }

            return $this->transition($actor, $tenant, $membership, MembershipStatus::Active, AuditEvent::MEMBERSHIP_REACTIVATED, revokeTokens: false);
        });
    }

    /** Remoção: encerra o vínculo, REVOGA todos os papéis (sem herança de privilégio num futuro convite) e os tokens. */
    public function remove(User $actor, Tenant $tenant, TenantUser $membership): TenantUser
    {
        return $this->lock->run($tenant, function () use ($actor, $tenant, $membership): TenantUser {
            $membership = $this->fresh($membership);

            if ($membership->membership_status === MembershipStatus::Removed) {
                throw ApiException::conflict('invalid_membership_state', 'Este vínculo já foi removido.');
            }

            $this->lastAdmin->assertNotLast($tenant, $membership->user_id, 'remover este usuário');

            $user = User::query()->findOrFail($membership->user_id);
            $this->context->run($tenant, function () use ($actor, $tenant, $membership, $user): void {
                foreach ($user->roles()->get() as $role) {
                    $user->removeRole($role);
                    $this->audit->record(AuditEvent::ROLE_REVOKED, tenantId: $tenant->getKey(), userId: $actor->getKey(),
                        entityType: 'tenant_user', entityId: $membership->getKey(),
                        old: ['role' => $role->name], metadata: ['target_user_id' => $membership->user_id, 'reason' => 'membership_removed']);
                }
            }, $user);

            return $this->transition($actor, $tenant, $membership, MembershipStatus::Removed, AuditEvent::MEMBERSHIP_REMOVED, revokeTokens: true);
        });
    }

    public function assignRole(User $actor, Tenant $tenant, TenantUser $membership, Role $role): bool
    {
        return $this->lock->run($tenant, function () use ($actor, $tenant, $membership, $role): bool {
            $membership = $this->fresh($membership);

            if ($membership->membership_status !== MembershipStatus::Active) {
                throw ApiException::conflict('invalid_membership_state', 'Só é possível atribuir papéis a vínculos ativos.');
            }
            $this->assertRoleBelongs($tenant, $role);
            $this->privileges->assertCanGrantRole($actor, $tenant, $role);

            $user = User::query()->findOrFail($membership->user_id);

            return $this->context->run($tenant, function () use ($actor, $tenant, $membership, $role, $user): bool {
                if ($user->hasRole($role)) {
                    return false; // idempotente: nada a gravar nem auditar
                }

                try {
                    $user->assignRole($role);
                } catch (QueryException) {
                    throw ApiException::unprocessable('invalid_role_assignment', 'Não foi possível atribuir este papel a este usuário.');
                }

                $this->audit->record(AuditEvent::ROLE_ASSIGNED, tenantId: $tenant->getKey(), userId: $actor->getKey(),
                    entityType: 'tenant_user', entityId: $membership->getKey(),
                    new: ['role' => $role->name], metadata: ['target_user_id' => $membership->user_id, 'role_id' => $role->getKey()]);

                return true;
            }, $user);
        });
    }

    public function revokeRole(User $actor, Tenant $tenant, TenantUser $membership, Role $role): bool
    {
        return $this->lock->run($tenant, function () use ($actor, $tenant, $membership, $role): bool {
            $membership = $this->fresh($membership);
            $this->assertRoleBelongs($tenant, $role);

            if ($role->name === PermissionCatalog::ADMIN_ROLE) {
                $this->lastAdmin->assertNotLast($tenant, $membership->user_id, 'remover o papel de administrador');
            }

            $user = User::query()->findOrFail($membership->user_id);

            return $this->context->run($tenant, function () use ($actor, $tenant, $membership, $role, $user): bool {
                if (! $user->hasRole($role)) {
                    return false;
                }

                $user->removeRole($role);

                $this->audit->record(AuditEvent::ROLE_REVOKED, tenantId: $tenant->getKey(), userId: $actor->getKey(),
                    entityType: 'tenant_user', entityId: $membership->getKey(),
                    old: ['role' => $role->name], metadata: ['target_user_id' => $membership->user_id, 'role_id' => $role->getKey()]);

                return true;
            }, $user);
        });
    }

    /** Revoga os tokens do USUÁRIO neste TENANT (acesso suspenso/removido). */
    public function revokeTokens(Tenant $tenant, int $userId): int
    {
        return PersonalAccessToken::query()->where('tenant_id', $tenant->getKey())->where('tokenable_id', $userId)->delete();
    }

    private function transition(User $actor, Tenant $tenant, TenantUser $membership, MembershipStatus $to, string $event, bool $revokeTokens): TenantUser
    {
        $from = $membership->membership_status;
        $revoked = $revokeTokens ? $this->revokeTokens($tenant, $membership->user_id) : 0;

        $membership->forceFill(['membership_status' => $to])->save();

        $this->audit->record($event, tenantId: $tenant->getKey(), userId: $actor->getKey(),
            entityType: 'tenant_user', entityId: $membership->getKey(),
            old: ['membership_status' => $from->value], new: ['membership_status' => $to->value],
            metadata: ['target_user_id' => $membership->user_id, 'tokens_revoked' => $revoked]);

        return $membership;
    }

    /** Relê o vínculo já sob o lock (o objeto recebido pode estar desatualizado). */
    private function fresh(TenantUser $membership): TenantUser
    {
        return TenantUser::query()->where('tenant_id', $membership->tenant_id)->findOrFail($membership->getKey());
    }

    private function assertRoleBelongs(Tenant $tenant, Role $role): void
    {
        if ((int) $role->tenant_id !== (int) $tenant->getKey()) {
            throw ApiException::notFound('Papel não encontrado.'); // papel de outro tenant: mesma resposta de "inexistente"
        }
    }
}
