<?php

namespace App\Models;

use App\Modules\Registry\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Insumo do catálogo da organização (Fase 4C, D-P37): material, mão de obra, equipamento (custo — D-P38) ou serviço.
 * O preço da mão de obra pode vir do "balanço" vigente (D-P39), gravado pelo ResourceCatalogService.
 */
class Resource extends Model
{
    use BelongsToTenant, HasPublicUuid, SoftDeletes;

    public const KINDS = ['material', 'labor', 'equipment', 'service'];

    protected $fillable = [
        'kind', 'code', 'name', 'unit_id', 'group_id', 'brand', 'model', 'main_supplier_id', 'job_title_id', 'equipment_id',
        'price', 'price_date', 'notes', 'status',
    ];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'price_date' => 'date'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ResourceGroup::class, 'group_id');
    }

    public function mainSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'main_supplier_id');
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function laborCosts(): HasMany
    {
        return $this->hasMany(ResourceLaborCost::class)->orderByDesc('effective_date');
    }
}
