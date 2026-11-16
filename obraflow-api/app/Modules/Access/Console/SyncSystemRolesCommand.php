<?php

namespace App\Modules\Access\Console;

use App\Models\Role;
use App\Models\Tenant;
use App\Modules\Access\Services\PermissionCatalog;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Alinha papéis de SISTEMA de um tenant existente com PermissionCatalog::systemRoles(), SOMENTE acrescentando
 * o que falta (nunca remove, nunca toca permissões fora da definição, nunca altera papéis personalizados).
 * Idempotente: sem diferença, não grava nem audita. `--dry-run` mostra a lista exata sem gravar.
 * Cada papel alterado gera `permission.granted` na MESMA transação.
 */
class SyncSystemRolesCommand extends Command
{
    protected $signature = 'obraflow:system-roles:sync {slug : slug do tenant} {--role=* : papel(is) de sistema (obrigatório)} {--dry-run : apenas mostra o que seria concedido} {--yes : não pedir confirmação}';

    protected $description = 'Acrescenta a papéis de sistema de um tenant as permissões que faltam conforme o catálogo (auditado; nunca remove).';

    public function handle(TenantContext $context, AuditLogger $audit, PermissionRegistrar $registrar): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('slug'))->first();
        $roles = array_values(array_unique((array) $this->option('role')));
        $definitions = PermissionCatalog::systemRoles();

        if (! $tenant) {
            $this->error('Tenant não encontrado.');

            return self::FAILURE;
        }
        if ($roles === [] || array_diff($roles, array_keys($definitions)) !== []) {
            $this->error('Informe --role com papel(is) de sistema: '.implode(', ', array_keys($definitions)).'.');

            return self::FAILURE;
        }

        $plan = $context->run($tenant, function () use ($tenant, $roles, $definitions) {
            $plan = [];
            foreach ($roles as $name) {
                $role = Role::query()->forTenant($tenant->id)->where('name', $name)->where('is_system', true)->first();
                if ($role === null) {
                    return null;
                }
                $plan[$name] = array_values(array_diff($definitions[$name], $role->permissions()->pluck('name')->all()));
            }

            return $plan;
        });

        if ($plan === null) {
            $this->error('Papel de sistema não encontrado neste tenant.');

            return self::FAILURE;
        }

        foreach ($plan as $name => $missing) {
            $this->line(sprintf('%s → %s', $name, $missing === [] ? '(nada a conceder)' : implode(', ', $missing)));
        }

        if (array_merge(...array_values($plan)) === []) {
            $this->info('Nada a fazer: os papéis já estão alinhados ao catálogo.');

            return self::SUCCESS;
        }
        if ($this->option('dry-run')) {
            $this->warn('Simulação: nada foi gravado.');

            return self::SUCCESS;
        }
        if (! $this->option('yes') && ! $this->confirm("Conceder as permissões acima no tenant \"{$tenant->slug}\"?")) {
            $this->warn('Cancelado.');

            return self::FAILURE;
        }

        $context->run($tenant, function () use ($tenant, $plan, $audit): void {
            DB::transaction(function () use ($tenant, $plan, $audit): void {
                foreach ($plan as $name => $missing) {
                    if ($missing === []) {
                        continue;
                    }
                    $role = Role::query()->forTenant($tenant->id)->where('name', $name)->lockForUpdate()->firstOrFail();
                    // recalcula sob lock (idempotente mesmo com execuções concorrentes)
                    $missing = array_values(array_diff($missing, $role->permissions()->pluck('name')->all()));
                    if ($missing === []) {
                        continue;
                    }
                    $role->givePermissionTo($missing);
                    $audit->record(AuditEvent::PERMISSION_GRANTED, tenantId: $tenant->id, userId: null,
                        entityType: 'role', entityId: $role->id, new: ['role' => $name, 'permissions' => $missing],
                        metadata: ['source' => 'artisan', 'command' => 'obraflow:system-roles:sync']);
                }
            });
        });

        $registrar->forgetCachedPermissions();
        $this->info('Permissões concedidas.');

        return self::SUCCESS;
    }
}
