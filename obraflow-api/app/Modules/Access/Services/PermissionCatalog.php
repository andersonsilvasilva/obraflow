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
        // Fase 2 — cadastros
        'units.view' => 'units',
        'units.manage' => 'units',
        'companies.view' => 'companies',
        'companies.manage' => 'companies',
        'customers.view' => 'customers',
        'customers.manage' => 'customers',
        'suppliers.view' => 'suppliers',
        'suppliers.manage' => 'suppliers',
        'documents.view' => 'documents',
        'documents.manage' => 'documents',
        // Fase 3 — equipes e obras (D-P28). Cargos e setores sob employees.*; grupos de recursos sob equipment.*
        'employees.view' => 'employees',
        'employees.manage' => 'employees',
        'equipment.view' => 'equipment',
        'equipment.manage' => 'equipment',
        'projects.view' => 'projects',
        'projects.manage' => 'projects',
        // Fase 4 — referências técnicas (globais, somente leitura; escrita só pelo comando de importação)
        'references.view' => 'references',
        // Fase 4B — orçamentos (aprovar não exige editar; D-P34)
        'budgets.view' => 'budgets',
        'budgets.manage' => 'budgets',
        'budgets.approve' => 'budgets',
        // Fase 4C — catálogo de insumos da organização (D-P37)
        'resources.view' => 'resources',
        'resources.manage' => 'resources',
    ];

    public const ADMIN_ROLE = 'admin';

    /**
     * Permissões de GESTÃO DE ACESSO: nunca podem ser abilities de um token (D-29). Um token vazado não pode
     * emitir outros tokens, mexer em papéis, suspender usuários ou convidar pessoas.
     */
    public const RESTRICTED_FOR_TOKENS = ['tokens.manage', 'roles.manage', 'members.manage', 'members.invite'];

    /** @return list<string> abilities que um token PODE receber (catálogo menos gestão de acesso) */
    public static function tokenAssignable(): array
    {
        return array_values(array_diff(array_keys(self::PERMISSIONS), self::RESTRICTED_FOR_TOKENS));
    }

    /**
     * Papéis de sistema criados em cada tenant no provisionamento (não podem ser excluídos nem editados).
     * Mudanças aqui valem para tenants NOVOS; papéis de tenants existentes só mudam com autorização expressa.
     */
    public static function systemRoles(): array
    {
        return [
            self::ADMIN_ROLE => array_keys(self::PERMISSIONS),
            'member' => ['tenant.view', 'members.view', 'roles.view', 'settings.view',
                'units.view', 'companies.view', 'customers.view', 'suppliers.view', 'documents.view',
                // D-P28: sem employees.view (dados pessoais); obras visíveis só com acesso por obra (D-P24)
                'equipment.view', 'projects.view',
                // Fase 4: bases técnicas públicas
                'references.view', 'budgets.view', 'resources.view'],
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
