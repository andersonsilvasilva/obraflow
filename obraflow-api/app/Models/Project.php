<?php

namespace App\Models;

use App\Modules\Registry\Concerns\HasPublicUuid;
use App\Modules\Shared\Exceptions\DomainRuleException;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Obra. Responsável técnico = funcionário da mesma empresa (FK no banco); cliente da mesma empresa (regra aqui).
 * O status só muda pelo ProjectStatusService (transição permitida + histórico + auditoria, D-P27).
 */
class Project extends Model
{
    use BelongsToTenant, HasPublicUuid, SoftDeletes;

    public const STATUSES = ['planning', 'in_progress', 'paused', 'delayed', 'completed', 'canceled'];

    /** Transições permitidas (D-P27). Estados finais não saem. */
    public const TRANSITIONS = [
        'planning' => ['in_progress', 'canceled'],
        'in_progress' => ['paused', 'delayed', 'completed', 'canceled'],
        'paused' => ['in_progress', 'canceled'],
        'delayed' => ['in_progress', 'paused', 'completed', 'canceled'],
        'completed' => [],
        'canceled' => [],
    ];

    protected $fillable = [
        'company_id', 'customer_id', 'code', 'name', 'description', 'technical_manager_id', 'contract_value',
        'progress', 'start_date', 'expected_end_date', 'actual_end_date',
    ];

    protected $attributes = ['status' => 'planning', 'progress' => 0];

    protected function casts(): array
    {
        return [
            'contract_value' => 'decimal:2', 'progress' => 'integer',
            'start_date' => 'date', 'expected_end_date' => 'date', 'actual_end_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $project): void {
            if ($project->customer_id !== null && $project->isDirty(['customer_id', 'company_id'])) {
                $customer = Customer::query()->find($project->customer_id);
                if ($customer !== null && (int) $customer->company_id !== (int) $project->company_id) {
                    throw new DomainRuleException('O cliente da obra deve ser da mesma empresa.');
                }
            }
        });
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function technicalManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technical_manager_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(ProjectAddress::class);
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ProjectPhase::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ProjectStatusHistory::class);
    }
}
