<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Papel SEMPRE de um tenant (D-18). NÃO usa o escopo global BelongsToTenant — o cache de permissões do
 * Spatie é global e carrega esta relação; um escopo faria o cache refletir apenas o primeiro tenant.
 * O isolamento é do banco (FKs compostas) e das consultas explícitas (scopeForTenant).
 */
class Role extends SpatieRole
{
    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @param  Builder<Role>  $query */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where($this->getTable().'.tenant_id', $tenantId);
    }
}
