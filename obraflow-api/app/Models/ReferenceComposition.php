<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Composição de uma base, com classificação e custos publicados. */
class ReferenceComposition extends Model
{
    protected $fillable = [
        'dataset_id', 'code', 'description', 'unit_id', 'class_code', 'class_name', 'type_code', 'type_name',
        'group_code', 'group_name', 'price_origin', 'total_cost', 'labor_cost', 'material_cost', 'equipment_cost',
        'services_cost', 'other_cost',
    ];

    protected function casts(): array
    {
        return [
            'total_cost' => 'decimal:2', 'labor_cost' => 'decimal:2', 'material_cost' => 'decimal:2',
            'equipment_cost' => 'decimal:2', 'services_cost' => 'decimal:2', 'other_cost' => 'decimal:2',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(ReferenceDataset::class, 'dataset_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ReferenceUnit::class, 'unit_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReferenceCompositionItem::class, 'composition_id')->orderBy('sort_order');
    }
}
