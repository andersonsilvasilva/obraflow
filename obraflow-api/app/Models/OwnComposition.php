<?php

namespace App\Models;

use App\Modules\Registry\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Composição própria simplificada da organização (6B, D-P87): um valor por tipo para uma unidade do serviço;
 * equipamento e mão de obra conforme o regime de encargos (D-P88).
 */
class OwnComposition extends Model
{
    use BelongsToTenant, HasPublicUuid, SoftDeletes;

    public const VALUES = ['material_value', 'service_value', 'other_value', 'equipment_value_none', 'equipment_value_relief',
        'equipment_value_no_relief', 'labor_value_none', 'labor_value_relief', 'labor_value_no_relief'];

    /** Regime de encargos da obra → sufixo da coluna (D-P88). */
    public const REGIME_SUFFIX = ['with_relief' => 'relief', 'without_relief' => 'no_relief', 'not_applicable' => 'none'];

    protected $fillable = ['code', 'name', 'unit_id', 'reference_date', 'state', ...self::VALUES, 'notes', 'status'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['reference_date' => 'date', ...array_fill_keys(self::VALUES, 'decimal:2')];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    /**
     * Custo unitário por tipo no regime da obra.
     *
     * @return array{material: string, labor: string, equipment: string, service: string, other: string}
     */
    public function costsFor(string $regime): array
    {
        $suffix = self::REGIME_SUFFIX[$regime] ?? 'none';

        return [
            'material' => (string) $this->material_value,
            'labor' => (string) $this->{"labor_value_{$suffix}"},
            'equipment' => (string) $this->{"equipment_value_{$suffix}"},
            'service' => (string) $this->service_value,
            'other' => (string) $this->other_value,
        ];
    }
}
