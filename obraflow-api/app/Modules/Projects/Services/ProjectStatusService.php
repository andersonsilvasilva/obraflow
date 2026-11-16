<?php

namespace App\Modules\Projects\Services;

use App\Models\Project;
use App\Models\ProjectStatusHistory;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Única forma de mudar o status de uma obra (D-P27): transição permitida (Project::TRANSITIONS),
 * histórico append-only e auditoria na MESMA transação, com a linha da obra travada (sem corrida).
 */
final class ProjectStatusService
{
    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context) {}

    public function change(Project $project, string $to, ?string $reason, User $actor): Project
    {
        return DB::transaction(function () use ($project, $to, $reason, $actor) {
            $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! Project::canTransition($from, $to)) {
                throw ApiException::unprocessable('invalid_transition', "Transição de status não permitida: {$from} → {$to}.");
            }

            $locked->forceFill(['status' => $to])->save();

            $history = new ProjectStatusHistory;
            $history->forceFill([
                'project_id' => $locked->id, 'from_status' => $from, 'to_status' => $to,
                'changed_by' => $actor->getKey(), 'reason' => $reason,
            ])->save();

            $this->audit->record(AuditEvent::PROJECT_STATUS_CHANGED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
                entityType: 'project', entityId: $locked->id, old: ['status' => $from], new: ['status' => $to],
                metadata: $reason === null ? [] : ['reason' => $reason]);

            return $project->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
