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
 * Orçamento (Fase 4B). Status, versão corrente e dono são definidos pelo BudgetService (não preenchíveis).
 * Obra e cliente precisam ser da mesma empresa do orçamento (regra aqui; FK garante o tenant).
 */
class Budget extends Model
{
    use BelongsToTenant, HasPublicUuid, SoftDeletes;

    public const MODES = ['customer', 'manual', 'reference'];

    public const STATUSES = ['draft', 'in_review', 'approved', 'rejected', 'archived'];

    protected $fillable = [
        'company_id', 'project_id', 'customer_id', 'code', 'title', 'mode', 'reference_dataset_id', 'state',
        'social_charges_regime', 'reference_date', 'notes',
    ];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['reference_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $budget): void {
            if ($budget->project_id && $budget->isDirty(['project_id', 'company_id'])) {
                $project = Project::query()->find($budget->project_id);
                if ($project && (int) $project->company_id !== (int) $budget->company_id) {
                    throw new DomainRuleException('A obra do orçamento deve ser da mesma empresa.');
                }
            }
            if ($budget->customer_id && $budget->isDirty(['customer_id', 'company_id'])) {
                $customer = Customer::query()->find($budget->customer_id);
                if ($customer && (int) $customer->company_id !== (int) $budget->company_id) {
                    throw new DomainRuleException('O cliente do orçamento deve ser da mesma empresa.');
                }
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(ReferenceDataset::class, 'reference_dataset_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BudgetVersion::class)->orderBy('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class, 'current_version_id');
    }
}
