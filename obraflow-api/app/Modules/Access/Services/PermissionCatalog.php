<?php

namespace App\Modules\Access\Services;

use App\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Catálogo GLOBAL de permissões e papéis iniciais. É DADO DE REFERÊNCIA instalado por comando idempotente
 * (`obraflow:sync-permissions`), versionado no código — não é seeder de demonstração. Nunca apaga nada.
 * Convenção: `modulo.acao`.
 */
final class PermissionCatalog
{
    /** @var array<string, string> permissão => módulo */
    public const PERMISSIONS = [
        'tenant.view' => 'tenant',
        'tenant.update' => 'tenant',
        'members.view' => 'members',
        'members.invite' => 'members',
        'members.manage' => 'members',
        'roles.view' => 'roles',
        'roles.manage' => 'roles',
        'settings.view' => 'settings',
        'settings.manage' => 'settings',
        'tokens.manage' => 'tokens',
        'audit.view' => 'audit',
    ];

    public const ADMIN_ROLE = 'admin';

    /** Papéis de sistema criados em cada tenant no provisionamento (não podem ser excluídos nem editados). */
    public static function systemRoles(): array
    {
        return [
            self::ADMIN_ROLE => array_keys(self::PERMISSIONS),
            'member' => ['tenant.view', 'members.view', 'roles.view', 'settings.view'],
            'viewer' => ['tenant.view'],
        ];
    }

    /** @return array{created: list<string>, existing: list<string>} */
    public function sync(): array
    {
        $created = [];
        $existing = [];

        foreach (self::PERMISSIONS as $name => $module) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['module' => $module],
            );

            if ($permission->wasRecentlyCreated) {
                $created[] = $name;

                continue;
            }

            if ($permission->module !== $module) {
                $permission->forceFill(['module' => $module])->save();
            }
            $existing[] = $name;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['created' => $created, 'existing' => $existing];
    }
}
