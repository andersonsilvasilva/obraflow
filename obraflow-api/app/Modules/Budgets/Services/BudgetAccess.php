<?php

namespace App\Modules\Budgets\Services;

use App\Models\Budget;
use App\Models\Project;
use App\Models\User;
use App\Modules\Projects\Services\ProjectAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quem pode o quê em cada orçamento (D-P34): permissão de orçamento (`budgets.view/manage/approve`) E, se o
 * orçamento é de uma obra, o acesso por obra (D-P24): ver/aprovar exige nível `view` na obra; editar exige `edit`.
 * Orçamento sem obra: só a permissão. Orçamento que o usuário não pode ver → 404 (não revela existência).
 */
final class BudgetAccess
{
    public function __construct(private readonly ProjectAccess $projects) {}

    public function allows(User $user, Budget $budget, string $ability): bool
    {
        $permission = ['view' => 'budgets.view', 'edit' => 'budgets.manage', 'approve' => 'budgets.approve'][$ability];
        if (! $user->can($permission)) {
            return false;
        }
        if ($budget->project_id === null) {
            return true;
        }
        $project = Project::query()->find($budget->project_id);

        return $project !== null && $this->projects->allows($user, $project, $ability === 'edit' ? 'edit' : 'view');
    }

    /** Restringe a consulta aos orçamentos visíveis. */
    public function visible(Builder $query, User $user): Builder
    {
        if (! $user->can('budgets.view')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q->whereNull('budgets.project_id')
            ->orWhereIn('budgets.project_id', $this->projects->visible(Project::query(), $user)->select('projects.id')));
    }
}
