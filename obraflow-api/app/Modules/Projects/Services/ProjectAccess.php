<?php

namespace App\Modules\Projects\Services;

use App\Models\Project;
use App\Models\User;
use App\Models\UserProjectAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quem pode o quê em cada obra (D-P24):
 *  - `projects.manage` → todas as obras, nível manage;
 *  - sem `projects.view` → nenhuma (mesmo com linha de acesso);
 *  - demais → só obras com linha em user_project_access; o nível (view < edit < manage) LIMITA o que a
 *    permissão permite (interseção, como em documentos).
 * As checagens passam por $user->can(), que respeita as abilities do token (D-29).
 * Obra que o usuário não pode VER deve ser respondida como 404 (não revela existência).
 */
final class ProjectAccess
{
    public const RANK = ['view' => 1, 'edit' => 2, 'manage' => 3];

    /** Nível efetivo do usuário na obra, ou null se não pode vê-la. */
    public function level(User $user, Project $project): ?string
    {
        if ($user->can('projects.manage')) {
            return 'manage';
        }
        if (! $user->can('projects.view')) {
            return null;
        }

        return UserProjectAccess::query()
            ->where('user_id', $user->getKey())->where('project_id', $project->getKey())
            ->value('access_level');
    }

    public function allows(User $user, Project $project, string $ability): bool
    {
        $level = $this->level($user, $project);

        return $level !== null && self::RANK[$level] >= self::RANK[$ability];
    }

    /** Restringe uma consulta de obras às que o usuário pode ver. */
    public function visible(Builder $query, User $user): Builder
    {
        if ($user->can('projects.manage')) {
            return $query;
        }
        if (! $user->can('projects.view')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            $query->getModel()->getTable().'.id',
            UserProjectAccess::query()->select('project_id')->where('user_id', $user->getKey()),
        );
    }
}
