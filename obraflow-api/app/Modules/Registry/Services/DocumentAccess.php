<?php

namespace App\Modules\Registry\Services;

use App\Models\Document;
use App\Models\DocumentPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quem pode o quê em cada documento (política da Fase 2):
 *  - `documents.manage` → tudo;
 *  - sem `documents.view` → nada;
 *  - visibilidade `private` → quem tem `documents.view` pode ver e baixar; editar exige `documents.manage`;
 *  - visibilidade `restricted` → além de `documents.view`, exige linha em document_permissions para o usuário
 *    ou um de seus papéis NO TENANT, com ability >= a pedida (view < download < edit < manage).
 * As checagens de permissão passam por $user->can(), que respeita as abilities do token (D-29).
 * Documento que o usuário não pode VER é respondido como 404 pelos controllers (não revela existência).
 */
final class DocumentAccess
{
    public const RANK = ['view' => 1, 'download' => 2, 'edit' => 3, 'manage' => 4];

    public function allows(User $user, Document $document, string $ability): bool
    {
        if ($user->can('documents.manage')) {
            return true;
        }
        if (! $user->can('documents.view')) {
            return false;
        }
        if ($document->visibility === 'private') {
            return self::RANK[$ability] <= self::RANK['download'];
        }

        return $this->grantedRank($user, $document) >= self::RANK[$ability];
    }

    /** Restringe uma consulta de documentos aos que o usuário pode ver. */
    public function visible(Builder $query, User $user): Builder
    {
        if ($user->can('documents.manage')) {
            return $query;
        }
        if (! $user->can('documents.view')) {
            return $query->whereRaw('1 = 0');
        }

        $roleIds = $this->roleIds($user);

        return $query->where(function (Builder $q) use ($user, $roleIds) {
            $q->where('visibility', 'private')
                ->orWhereIn('id', DocumentPermission::query()->select('document_id')
                    ->where(fn ($p) => $this->subjects($p, $user, $roleIds)));
        });
    }

    private function grantedRank(User $user, Document $document): int
    {
        $abilities = DocumentPermission::query()->where('document_id', $document->getKey())
            ->where(fn ($p) => $this->subjects($p, $user, $this->roleIds($user)))
            ->pluck('ability');

        return (int) $abilities->map(fn (string $a) => self::RANK[$a] ?? 0)->max();
    }

    /** @param  list<int>  $roleIds */
    private function subjects(Builder $p, User $user, array $roleIds): void
    {
        $p->where(fn ($u) => $u->where('subject_type', 'user')->where('subject_id', $user->getKey()));
        if ($roleIds !== []) {
            $p->orWhere(fn ($r) => $r->where('subject_type', 'role')->whereIn('subject_id', $roleIds));
        }
    }

    /** Papéis do usuário no tenant ativo (o Spatie filtra pelo team = tenant definido no TenantContext). @return list<int> */
    private function roleIds(User $user): array
    {
        return $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();
    }
}
