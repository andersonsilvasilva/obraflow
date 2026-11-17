<?php

namespace App\Models;

use App\Modules\Budgets\Exceptions\VersionLockedException;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versão do orçamento (D-08). Bloqueada (`locked_at`) ⇒ somente leitura: a única mudança aceita é o status
 * `approved` → `superseded` quando outra versão é aprovada. Não pode ser excluída depois de bloqueada.
 */
class BudgetVersion extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'in_review', 'approved', 'rejected', 'superseded'];

    protected $fillable = ['label', 'discount_percent'];

    protected $attributes = ['status' => 'draft', 'discount_percent' => '0.0000'];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer', 'discount_percent' => 'decimal:4', 'totals_snapshot' => 'array',
            'approved_at' => 'datetime', 'locked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $v): void {
            if ($v->getOriginal('locked_at') === null) {
                return;
            }
            $dirty = array_values(array_diff(array_keys($v->getDirty()), ['updated_at']));
            $supersede = $dirty === ['status'] && $v->getOriginal('status') === 'approved' && $v->status === 'superseded';
            if (! $supersede) {
                throw new VersionLockedException;
            }
        });
        static::deleting(function (self $v): void {
            if ($v->locked_at !== null) {
                throw new VersionLockedException;
            }
        });
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(BudgetGroup::class)->orderBy('sort_order')->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
