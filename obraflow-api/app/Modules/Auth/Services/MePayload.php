<?php

namespace App\Modules\Auth\Services;

use App\Enums\MembershipStatus;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\ActiveTenantResolver;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Monta a resposta de /auth/me e do login: usuário, vínculos ativos, tenant ativo e permissões nele. */
final class MePayload
{
    public function __construct(
        private readonly ActiveTenantResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    /** @return array<string, mixed> */
    public function build(Request $request, User $user): array
    {
        $memberships = TenantUser::query()
            ->with('tenant')
            ->where('user_id', $user->getKey())
            ->where('membership_status', MembershipStatus::Active->value)
            ->get()
            ->filter(fn (TenantUser $m) => $m->tenant !== null);

        $active = null;
        $permissions = null;

        try {
            [$tenant] = $this->resolver->resolve($request, $user);
            $active = $this->tenantArray($tenant);
            $permissions = $this->context->run(
                $tenant,
                fn () => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
                $user,
            );
        } catch (ApiException) {
            // sem tenant ativo válido: /me continua respondendo (é rota de `auth/*`)
        }

        return [
            'user' => ['uuid' => $user->uuid, 'name' => $user->name, 'email' => $user->email],
            'tenants' => $memberships->map(fn (TenantUser $m) => $this->tenantArray($m->tenant))->values()->all(),
            'active_tenant' => $active,
            'permissions' => $permissions,
        ];
    }

    /** @return array<string, mixed> */
    private function tenantArray(Tenant $tenant): array
    {
        return ['uuid' => $tenant->uuid, 'name' => $tenant->name, 'slug' => $tenant->slug, 'status' => $tenant->status->value];
    }
}
