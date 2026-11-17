<?php

namespace App\Models;

use App\Modules\Budgets\Concerns\BelongsToBudgetVersion;
use App\Modules\Shared\Exceptions\DomainRuleException;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Etapa (EAP) do orçamento. Pai da mesma versão (FK); até MAX_DEPTH níveis e sem ciclo (aqui). */
class BudgetGroup extends Model
{
    use BelongsToBudgetVersion, BelongsToTenant;

    public const MAX_DEPTH = 4;

    protected $fillable = ['budget_version_id', 'parent_id', 'code', 'name', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $g): void {
            $depth = 1;
            $cursor = $g->parent_id ? self::query()->find($g->parent_id) : null;
            while ($cursor) {
                if ($g->exists && (int) $cursor->id === (int) $g->id) {
                    throw new DomainRuleException('A etapa não pode estar abaixo de si mesma.');
                }
                $depth++;
                $cursor = $cursor->parent_id ? self::query()->find($cursor->parent_id) : null;
            }
            if ($depth > self::MAX_DEPTH) {
                throw new DomainRuleException('Etapas do orçamento têm no máximo '.self::MAX_DEPTH.' níveis.');
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class, 'group_id');
    }
}
