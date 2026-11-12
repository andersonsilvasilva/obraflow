<?php

namespace App\Modules\Tenancy\Concerns;

use App\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Isolamento por tenant no Eloquent (MULTITENANCY.md §5):
 *  - escopo global `tenant_id = tenant ativo` — SEM contexto lança exceção (nunca devolve "tudo");
 *  - `creating` preenche tenant_id a partir do contexto e IGNORA qualquer valor vindo do cliente;
 *  - `tenant_id` é imutável.
 * Nunca coloque `tenant_id` em $fillable.
 *
 * NÃO use em Role/Permission: o cache de permissões do Spatie é global e carrega as relações de papéis;
 * um escopo ali faria o cache refletir só o primeiro tenant carregado.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $query->where(
                $query->getModel()->getTable().'.tenant_id',
                app(TenantContext::class)->idOrFail(),
            );
        });

        static::creating(function ($model): void {
            $model->tenant_id = app(TenantContext::class)->idOrFail();
        });

        static::updating(function ($model): void {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('tenant_id é imutável.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
