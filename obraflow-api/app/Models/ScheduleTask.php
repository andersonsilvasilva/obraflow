<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Atividade do planejamento (6A): etapa (`summary`), atividade (`task`) ou marco (`milestone`). Datas, duração,
 * WBS e o % das etapas são calculados pelo Scheduler (D-P66/D-P67) — gravados só pelo ScheduleService.
 */
class ScheduleTask extends Model
{
    use BelongsToTenant;

    public const KINDS = ['summary', 'task', 'milestone'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'planned_start' => 'date', 'planned_end' => 'date', 'actual_start' => 'date', 'actual_end' => 'date',
            'quantity' => 'decimal:4', 'percent_complete' => 'decimal:2', 'duration_days' => 'integer', 'sort_order' => 'integer',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
