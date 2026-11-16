<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Grupo/subgrupo de recursos (D-P22). Dois níveis: um subgrupo não pode ter filhos.
 * `parent_key` é coluna gerada: nunca é gravada. O tipo do pai é garantido pela FK (tenant_id, kind, parent_id).
 */
class ResourceGroup extends Model
{
    use BelongsToTenant;

    public const KINDS = ['equipment', 'material'];

    protected $fillable = ['kind', 'parent_id', 'name'];

    protected static function booted(): void
    {
        static::saving(function (self $group): void {
            if ($group->parent_id === null) {
                return;
            }
            if ($group->exists && (int) $group->parent_id === (int) $group->id) {
                throw new LogicException('Um grupo não pode ser pai de si mesmo.');
            }
            $parent = self::query()->find($group->parent_id);
            if ($parent?->parent_id !== null) {
                throw new LogicException('Grupos de recursos têm no máximo dois níveis (grupo e subgrupo).');
            }
            if ($group->exists && $group->children()->exists()) {
                throw new LogicException('Um grupo com subgrupos não pode virar subgrupo.');
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
}
