<?php

namespace App\Modules\Registry\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/** uuid público na coluna `uuid` (a PK continua BIGINT). Rotas resolvem por uuid, nunca pelo id interno. */
trait HasPublicUuid
{
    use HasUuids;

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
