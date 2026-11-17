<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Horário de trabalho de um dia da semana (D-P63): da empresa (padrão) ou da obra (próprio). Gravado só pelo
 * WorkScheduleService, que substitui a semana inteira de um dono numa transação.
 */
class WorkSchedule extends Model
{
    use BelongsToTenant;

    protected $fillable = ['weekday', 'start_1', 'end_1', 'start_2', 'end_2'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }
}
