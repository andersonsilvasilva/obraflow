<?php

namespace App\Modules\Contracts\Services;

use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use App\Modules\Projects\Services\ProjectAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quem pode o quê em cada contrato (D-P56): permissão de contrato (`contracts.view/manage`) E o acesso por obra
 * (D-P24) — o contrato pertence sempre a uma obra (A-45). Ver exige nível `view` na obra; alterar exige `edit`.
 * Contrato que o usuário não pode ver → 404 (não revela existência).
 */
final class ContractAccess
{
    public function __construct(private readonly ProjectAccess $projects) {}

    public function allows(User $user, Contract $contract, string $ability): bool
    {
        if (! $user->can($ability === 'edit' ? 'contracts.manage' : 'contracts.view')) {
            return false;
        }
        $project = Project::query()->find($contract->project_id);

        return $project !== null && $this->projects->allows($user, $project, $ability === 'edit' ? 'edit' : 'view');
    }

    /** Restringe a consulta aos contratos visíveis. */
    public function visible(Builder $query, User $user): Builder
    {
        if (! $user->can('contracts.view')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('contracts.project_id', $this->projects->visible(Project::query(), $user)->select('projects.id'));
    }
}
