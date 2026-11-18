<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recurso de uma atividade do planejamento (6B): cópia do preço (D-08) com o custo unitário separado por tipo e o
 * consumo por unidade da atividade (D-P91). Gravado só pelo serviço do planejamento; valores calculados pelo
 * ScheduleCostCalculator.
 */
class ScheduleTaskItem extends Model
{
    use BelongsToTenant;

    public const SOURCES = ['catalog', 'sinapi_resource', 'sinapi_composition', 'own_composition', 'budget', 'manual'];

    public const KINDS = ['material', 'labor', 'equipment', 'service', 'other', 'composition'];

    /** Tipos de custo (as colunas do orçamento sintético). */
    public const COST_KINDS = ['material', 'labor', 'equipment', 'service', 'other'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'source_ref' => 'array', 'breakdown' => 'array', 'quantity_per_unit' => 'decimal:7', 'unit_cost' => 'decimal:2',
            'cost_material' => 'decimal:2', 'cost_labor' => 'decimal:2', 'cost_equipment' => 'decimal:2', 'cost_service' => 'decimal:2',
            'cost_other' => 'decimal:2', 'bdi_percent' => 'decimal:4', 'discount_percent' => 'decimal:4', 'sort_order' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class, 'task_id');
    }
}
