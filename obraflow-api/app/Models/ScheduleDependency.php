<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Dependência entre atividades (6A, D-P67): FS, SS, FF ou SF, com atraso em dias úteis. */
class ScheduleDependency extends Model
{
    use BelongsToTenant;

    public const TYPES = ['FS', 'SS', 'FF', 'SF'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['lag_days' => 'integer'];
    }
}
