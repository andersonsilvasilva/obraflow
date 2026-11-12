<?php

namespace App\Modules\Access\Services;

use App\Enums\MembershipStatus;
use App\Enums\UserStatus;
use App\Models\Tenant;
use App\Modules\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Impede deixar um tenant sem nenhum administrador ATIVO (vínculo ativo + usuário ativo + papel `admin`).
 * Use SEMPRE dentro de TenantLock::run().
 */
final class LastAdminGuard
{
    /** Quantos administradores ativos restariam se `$userId` deixasse de ser administrador ativo. */
    public function remainingAdmins(Tenant $tenant, int $userId): int
    {
        return DB::table('model_has_roles')
            ->join('roles', function ($j): void {
                $j->on('roles.id', '=', 'model_has_roles.role_id')->on('roles.tenant_id', '=', 'model_has_roles.tenant_id');
            })
            ->join('tenant_users', function ($j): void {
                $j->on('tenant_users.tenant_id', '=', 'model_has_roles.tenant_id')->on('tenant_users.user_id', '=', 'model_has_roles.model_id');
            })
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.tenant_id', $tenant->getKey())
            ->where('roles.name', PermissionCatalog::ADMIN_ROLE)
            ->where('tenant_users.membership_status', MembershipStatus::Active->value)
            ->where('users.status', UserStatus::Active->value)
            ->where('model_has_roles.model_id', '<>', $userId)
            ->distinct()
            ->count('model_has_roles.model_id');
    }

    /** @throws ApiException 409 last_admin */
    public function assertNotLast(Tenant $tenant, int $userId, string $action): void
    {
        if ($this->remainingAdmins($tenant, $userId) === 0) {
            throw ApiException::conflict(
                'last_admin',
                "Não é possível {$action}: este é o último administrador ativo do tenant. Promova outro administrador antes.",
            );
        }
    }

    public function isActiveAdmin(Tenant $tenant, int $userId): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', function ($j): void {
                $j->on('roles.id', '=', 'model_has_roles.role_id')->on('roles.tenant_id', '=', 'model_has_roles.tenant_id');
            })
            ->join('tenant_users', function ($j): void {
                $j->on('tenant_users.tenant_id', '=', 'model_has_roles.tenant_id')->on('tenant_users.user_id', '=', 'model_has_roles.model_id');
            })
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.tenant_id', $tenant->getKey())
            ->where('model_has_roles.model_id', $userId)
            ->where('roles.name', PermissionCatalog::ADMIN_ROLE)
            ->where('tenant_users.membership_status', MembershipStatus::Active->value)
            ->where('users.status', UserStatus::Active->value)
            ->exists();
    }
}
