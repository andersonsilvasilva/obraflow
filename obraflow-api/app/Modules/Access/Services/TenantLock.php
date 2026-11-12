<?php

namespace App\Modules\Access\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Serializa operações que podem reduzir administradores/acessos de UM tenant (bloqueio de linha em `tenants`).
 * Sem isso, dois administradores removidos ao mesmo tempo passariam na checagem do "último administrador"
 * e deixariam o tenant sem ninguém.
 */
final class TenantLock
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        return DB::transaction(function () use ($tenant, $callback) {
            Tenant::query()->withTrashed()->whereKey($tenant->getKey())->lockForUpdate()->first();

            return $callback();
        });
    }
}
