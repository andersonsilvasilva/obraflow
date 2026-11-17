<?php

namespace App\Models;

use App\Modules\Registry\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contrato (Fase 5A, FASE5_MODELO.md). Pertence SEMPRE a uma obra (A-45). Sem exclusão: só status e cancelamento.
 * Status, valores (original/atual), prazo atual e autor são gravados pelo ContractService/ContractWorkflow (não
 * preenchíveis). Cliente ou fornecedor conforme o tipo (D-P45/D-P46; CHECK no banco).
 */
class Contract extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const KINDS = ['customer', 'supplier'];

    public const STATUSES = ['draft', 'active', 'suspended', 'completed', 'canceled'];

    protected $fillable = [
        'code', 'kind', 'company_id', 'project_id', 'customer_id', 'supplier_id', 'object', 'starts_at', 'original_end_date',
        'signed_at', 'retention_percent', 'readjustment_rule', 'notes',
    ];

    protected $attributes = ['status' => 'draft', 'original_value' => '0.00', 'current_value' => '0.00', 'retention_percent' => '0.0000'];

    protected function casts(): array
    {
        return [
            'original_value' => 'decimal:2', 'current_value' => 'decimal:2', 'retention_percent' => 'decimal:4',
            'starts_at' => 'date', 'original_end_date' => 'date', 'current_end_date' => 'date', 'signed_at' => 'date',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function budgetVersion(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContractItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(ContractAddendum::class)->orderBy('number');
    }
}
