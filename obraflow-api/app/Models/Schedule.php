<?php

namespace App\Models;

use App\Modules\Registry\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Planejamento da obra (Fase 6A): um por obra. Gravado só pelo ScheduleService. */
class Schedule extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const ORIGINS = ['budget', 'spreadsheet', 'blank'];

    protected $guarded = ['*'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function budgetVersion(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ScheduleTask::class);
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(ScheduleDependency::class);
    }

    public function baselines(): HasMany
    {
        return $this->hasMany(ScheduleBaseline::class)->orderBy('number');
    }
}
