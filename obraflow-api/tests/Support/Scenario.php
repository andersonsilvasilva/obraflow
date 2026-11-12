<?php

namespace Tests\Support;

use App\Enums\MembershipStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Access\Services\PermissionCatalog;
use App\Modules\Tenancy\TenantContext;

/** Monta cenários multi-tenant realistas (tenants, papéis de sistema, usuários, vínculos). */
final class Scenario
{
    public const PASSWORD = 'Senha-de-Teste-123';

    /** Instala o catálogo de permissões (dado de referência) — chame uma vez por teste. */
    public static function catalog(): void
    {
        app(PermissionCatalog::class)->sync();
    }

    /** Tenant com os papéis de sistema (admin, member, viewer) e suas permissões. */
    public static function tenant(array $attributes = []): Tenant
    {
        $tenant = Tenant::factory()->create($attributes);

        foreach (PermissionCatalog::systemRoles() as $name => $permissions) {
            $role = Concerns::role($tenant, $name, $permissions);
            $role->forceFill(['is_system' => true])->save();
        }

        return $tenant;
    }

    /** Usuário vinculado ao tenant com um papel. */
    public static function member(Tenant $tenant, string $role = 'admin', array $userAttributes = [], MembershipStatus $status = MembershipStatus::Active): User
    {
        $user = User::factory()->create($userAttributes);

        return self::attach($tenant, $user, $role, $status);
    }

    /** Vincula um usuário EXISTENTE (usuário global em vários tenants). */
    public static function attach(Tenant $tenant, User $user, string $role = 'admin', MembershipStatus $status = MembershipStatus::Active): User
    {
        Concerns::member($tenant, $user, $status);

        app(TenantContext::class)->run($tenant, function () use ($user, $role): void {
            $user->assignRole($role);
        }, $user);

        return $user;
    }
}
