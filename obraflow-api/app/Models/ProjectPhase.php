<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Fase da obra (árvore). Pai da mesma obra pela FK; profundidade máxima e ciclo verificados aqui. */
class ProjectPhase extends Model
{
    use BelongsToTenant;

    public const MAX_DEPTH = 3;

    protected $fillable = ['project_id', 'parent_id', 'name', 'sort_order', 'planned_start', 'planned_end'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'planned_start' => 'date', 'planned_end' => 'date'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $phase): void {
            if ($phase->parent_id === null) {
                return;
            }
            $depth = 1;
            $cursor = self::query()->find($phase->parent_id);
            while ($cursor !== null) {
                if ($phase->exists && (int) $cursor->id === (int) $phase->id) {
                    throw new LogicException('A fase não pode estar abaixo de si mesma.');
                }
                $depth++;
                $cursor = $cursor->parent_id === null ? null : self::query()->find($cursor->parent_id);
            }
            if ($depth > self::MAX_DEPTH) {
                throw new LogicException('Fases da obra têm no máximo '.self::MAX_DEPTH.' níveis.');
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
