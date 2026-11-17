<?php

namespace App\Modules\Budgets\Concerns;

use App\Models\BudgetVersion;
use App\Modules\Budgets\Exceptions\VersionLockedException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Filhos de uma versão (etapas, itens, composições, BDI…): nenhuma escrita quando a versão está bloqueada
 * (D-P31 — garantia da aplicação, sem trigger). Vale para criar, alterar e excluir.
 */
trait BelongsToBudgetVersion
{
    protected static function bootBelongsToBudgetVersion(): void
    {
        $guard = function ($model): void {
            $versionId = $model->budget_version_id ?? $model->getOriginal('budget_version_id');
            if ($versionId && BudgetVersion::query()->whereKey($versionId)->whereNotNull('locked_at')->exists()) {
                throw new VersionLockedException;
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class, 'budget_version_id');
    }
}
