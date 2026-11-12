<?php

namespace App\Modules\Access\Console;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Suspende, cancela ou reativa um tenant (efeito imediato: SetTenantContext revalida a cada requisição). */
class TenantStatusCommand extends Command
{
    protected $signature = 'obraflow:tenant:status {slug} {status : active|suspended|canceled}';

    protected $description = 'Altera o status de um tenant (auditado).';

    public function handle(AuditLogger $audit): int
    {
        $to = TenantStatus::tryFrom((string) $this->argument('status'));
        $tenant = Tenant::query()->where('slug', $this->argument('slug'))->first();

        if (! $to || ! in_array($to, [TenantStatus::Active, TenantStatus::Suspended, TenantStatus::Canceled], true)) {
            $this->error('Status inválido. Use active, suspended ou canceled.');

            return self::FAILURE;
        }
        if (! $tenant) {
            $this->error('Tenant não encontrado.');

            return self::FAILURE;
        }
        if ($tenant->status === $to) {
            $this->warn("O tenant já está \"{$to->value}\".");

            return self::SUCCESS;
        }

        $from = $tenant->status;

        DB::transaction(function () use ($tenant, $from, $to, $audit): void {
            $tenant->forceFill(['status' => $to])->save();
            $audit->record($to === TenantStatus::Active ? AuditEvent::TENANT_REACTIVATED : AuditEvent::TENANT_SUSPENDED,
                tenantId: $tenant->getKey(), userId: null, entityType: 'tenant', entityId: $tenant->getKey(),
                old: ['status' => $from->value], new: ['status' => $to->value], metadata: ['source' => 'artisan']);
        });

        $this->info("Tenant \"{$tenant->slug}\": {$from->value} → {$to->value}.");

        return self::SUCCESS;
    }
}
