<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Funcionário na equipe da obra. Um vínculo em aberto por funcionário e obra (`open_marker`, coluna gerada).
 * O funcionário deve ser da mesma empresa da obra (regra aqui).
 */
class ProjectMember extends Model
{
    use BelongsToTenant;

    protected $fillable = ['project_id', 'employee_id', 'role', 'started_on', 'ended_on'];

    protected function casts(): array
    {
        return ['started_on' => 'date', 'ended_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $member): void {
            if (! $member->isDirty(['project_id', 'employee_id'])) {
                return;
            }
            $project = Project::query()->find($member->project_id);
            $employee = Employee::query()->find($member->employee_id);
            if ($project !== null && $employee !== null && (int) $project->company_id !== (int) $employee->company_id) {
                throw new LogicException('O funcionário deve ser da mesma empresa da obra.');
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
