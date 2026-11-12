<?php

namespace Tests\Support;

use App\Enums\MembershipStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Tenancy\TenantContext;

/** Helpers de alto nível (Eloquent) compartilhados pelos testes de aplicação. */
final class Concerns
{
    public static function member(Tenant $tenant, User $user, MembershipStatus $status = MembershipStatus::Active): TenantUser
    {
        $m = new TenantUser;
        $m->forceFill([
            'tenant_id' => $tenant->getKey(), 'user_id' => $user->getKey(),
            'membership_status' => $status, 'joined_at' => now(),
        ])->save();

        return $m;
    }

    /** Cria um papel do tenant (com contexto definido, como a aplicação faria). */
    public static function role(Tenant $tenant, string $name, array $permissions = []): Role
    {
        return app(TenantContext::class)->run($tenant, function () use ($tenant, $name, $permissions) {
            /** @var Role $role */
            $role = Role::create(['name' => $name, 'guard_name' => 'web', 'tenant_id' => $tenant->getKey()]);
            if ($permissions) {
                $role->syncPermissions($permissions);
            }

            return $role;
        });
    }
}
