<?php

namespace App\Modules\Projects\Services;

use App\Models\Project;
use App\Models\User;
use App\Models\UserProjectAccess;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Concede, altera e revoga acesso a uma obra (D-P24), auditando na mesma transação.
 * O usuário-alvo já foi validado como membro ATIVO do tenant (a FK → tenant_users é a última defesa).
 */
final class ProjectAccessManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context) {}

    public function grant(Project $project, int $userId, string $level, User $actor): UserProjectAccess
    {
        return DB::transaction(function () use ($project, $userId, $level, $actor) {
            $row = UserProjectAccess::query()->where('project_id', $project->id)->where('user_id', $userId)->lockForUpdate()->first();
            $old = $row?->access_level;
            if ($old === $level) {
                return $row;
            }

            $row ??= new UserProjectAccess(['project_id' => $project->id, 'user_id' => $userId]);
            $row->fill(['access_level' => $level, 'granted_by' => $actor->getKey()])->save();

            $this->record(AuditEvent::PROJECT_ACCESS_GRANTED, $project, $actor,
                $old === null ? null : ['user_id' => $userId, 'access_level' => $old],
                ['user_id' => $userId, 'access_level' => $level]);

            return $row;
        });
    }

    public function revoke(Project $project, UserProjectAccess $row, User $actor): void
    {
        DB::transaction(function () use ($project, $row, $actor) {
            $row->delete();
            $this->record(AuditEvent::PROJECT_ACCESS_REVOKED, $project, $actor,
                ['user_id' => $row->user_id, 'access_level' => $row->access_level], null);
        });
    }

    private function record(string $event, Project $project, User $actor, ?array $old, ?array $new): void
    {
        $this->audit->record($event, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
            entityType: 'project', entityId: $project->id, old: $old, new: $new);
    }
}
