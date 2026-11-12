<?php

namespace App\Modules\Access\Services;

use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Access\Exceptions\ProvisioningException;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Auth\PasswordRules;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Provisionamento INICIAL (não há superadmin nesta fase): cria o tenant, o administrador, os papéis de sistema
 * e o vínculo, tudo numa transação, com auditoria. Recusa duplicidades; nunca cria "por acidente".
 */
final class ProvisionTenant
{
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{tenant: Tenant, admin: User, generated_password: ?string, admin_created: bool}
     *
     * @throws ProvisioningException
     */
    public function run(
        string $name,
        string $slug,
        string $adminEmail,
        string $adminName,
        ?string $adminPassword,
        bool $allowExistingAdmin = false,
        bool $allowDuplicateName = false,
        string $source = 'artisan',
    ): array {
        $name = trim($name);
        $slug = Str::lower(trim($slug));
        $email = Str::lower(trim($adminEmail));

        $this->validate($name, $slug, $email, $adminName);

        if (Tenant::query()->withTrashed()->where('slug', $slug)->exists()) {
            throw new ProvisioningException("Já existe um tenant com o slug \"{$slug}\" (inclusive removido). Nada foi criado.");
        }
        if (! $allowDuplicateName && Tenant::query()->withTrashed()->whereRaw('LOWER(name) = ?', [Str::lower($name)])->exists()) {
            throw new ProvisioningException("Já existe um tenant chamado \"{$name}\". Use --allow-duplicate-name se for intencional. Nada foi criado.");
        }

        $user = User::query()->where('email', $email)->first();

        if ($user && ! $allowExistingAdmin) {
            throw new ProvisioningException("O usuário {$email} já existe. Para vinculá-lo como administrador de um NOVO tenant, use --existing-admin. Nada foi criado.");
        }
        if ($user && ! $user->isActive()) {
            throw new ProvisioningException("O usuário {$email} está bloqueado. Nada foi criado.");
        }

        $generated = null;
        if (! $user) {
            if ($adminPassword === null) {
                $generated = $adminPassword = $this->generatePassword();
            } else {
                $this->assertStrong($adminPassword);
            }
        }

        return DB::transaction(function () use ($name, $slug, $email, $adminName, $adminPassword, $user, $generated, $source): array {
            $this->catalog->sync();

            $tenant = new Tenant;
            $tenant->forceFill(['name' => $name, 'slug' => $slug, 'status' => TenantStatus::Active])->save();

            $created = false;
            if (! $user) {
                $user = new User;
                $user->forceFill([
                    'name' => $adminName, 'email' => $email, 'password' => $adminPassword,
                    'status' => UserStatus::Active, 'email_verified_at' => now(),
                ])->save();
                $created = true;
            }

            $membership = new TenantUser;
            $membership->forceFill([
                'tenant_id' => $tenant->getKey(), 'user_id' => $user->getKey(),
                'membership_status' => MembershipStatus::Active, 'joined_at' => now(),
            ])->save();

            $this->audit->record(AuditEvent::TENANT_PROVISIONED, tenantId: $tenant->getKey(), userId: null,
                entityType: 'tenant', entityId: $tenant->getKey(), new: ['name' => $name, 'slug' => $slug],
                metadata: ['source' => $source, 'admin_user_id' => $user->getKey(), 'admin_account_created' => $created]);
            $this->audit->record(AuditEvent::MEMBERSHIP_CREATED, tenantId: $tenant->getKey(), userId: null,
                entityType: 'tenant_user', entityId: $membership->getKey(), new: ['membership_status' => 'active'],
                metadata: ['target_user_id' => $user->getKey(), 'via' => $source]);

            $this->context->run($tenant, function () use ($tenant, $user, $membership): void {
                foreach (PermissionCatalog::systemRoles() as $roleName => $permissions) {
                    /** @var Role $role */
                    $role = Role::create(['name' => $roleName, 'guard_name' => 'web', 'tenant_id' => $tenant->getKey()]);
                    $role->forceFill(['is_system' => true])->save();
                    $role->syncPermissions($permissions);

                    $this->audit->record(AuditEvent::ROLE_CREATED, tenantId: $tenant->getKey(), userId: null,
                        entityType: 'role', entityId: $role->getKey(), new: ['name' => $roleName, 'is_system' => true, 'permissions' => $permissions]);
                    $this->audit->record(AuditEvent::PERMISSION_GRANTED, tenantId: $tenant->getKey(), userId: null,
                        entityType: 'role', entityId: $role->getKey(), new: ['role' => $roleName, 'permissions' => $permissions]);

                    if ($roleName === PermissionCatalog::ADMIN_ROLE) {
                        $user->assignRole($role);
                        $this->audit->record(AuditEvent::ROLE_ASSIGNED, tenantId: $tenant->getKey(), userId: null,
                            entityType: 'tenant_user', entityId: $membership->getKey(), new: ['role' => $roleName],
                            metadata: ['target_user_id' => $user->getKey(), 'role_id' => $role->getKey(), 'via' => 'provisioning']);
                    }
                }
            }, $user);

            return ['tenant' => $tenant, 'admin' => $user, 'generated_password' => $generated, 'admin_created' => $created];
        });
    }

    private function validate(string $name, string $slug, string $email, string $adminName): void
    {
        $v = Validator::make(
            ['name' => $name, 'slug' => $slug, 'email' => $email, 'admin_name' => $adminName],
            [
                'name' => ['required', 'string', 'min:3', 'max:150'],
                'slug' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                'email' => ['required', 'email', 'max:190'],
                'admin_name' => ['required', 'string', 'min:2', 'max:150'],
            ],
        );

        if ($v->fails()) {
            throw new ProvisioningException('Dados inválidos: '.implode(' ', $v->errors()->all()));
        }
    }

    private function assertStrong(string $password): void
    {
        $v = Validator::make(['password' => $password], ['password' => [PasswordRules::strong()]]);

        if ($v->fails()) {
            throw new ProvisioningException('Senha fraca: '.implode(' ', $v->errors()->all()));
        }
    }

    private function generatePassword(): string
    {
        do {
            $password = Str::password(20, symbols: false);
        } while (Validator::make(['password' => $password], ['password' => [PasswordRules::strong()]])->fails());

        return $password;
    }
}
