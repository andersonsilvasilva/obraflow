<?php

namespace App\Models;

use App\Modules\Registry\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exceção do calendário da obra: dia sem trabalho, único ou recorrente (D-P57/D-P58).
 * As ocorrências são calculadas pelo ProjectCalendar (nada é gravado por dia).
 */
class ProjectCalendarException extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const KINDS = ['single', 'recurring'];

    public const UNITS = ['day', 'week', 'fortnight', 'month', 'year'];

    protected $fillable = ['description', 'date', 'kind', 'repeat_every', 'repeat_unit', 'repeat_until'];

    protected function casts(): array
    {
        return ['date' => 'date', 'repeat_until' => 'date', 'repeat_every' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
