<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item de composição: um insumo OU uma composição filha da mesma base (CHECK + FKs com dataset_id). */
class ReferenceCompositionItem extends Model
{
    protected $fillable = ['dataset_id', 'composition_id', 'resource_id', 'child_composition_id', 'coefficient', 'unit_price', 'cost', 'price_origin', 'sort_order'];

    protected function casts(): array
    {
        return ['coefficient' => 'decimal:7', 'unit_price' => 'decimal:2', 'cost' => 'decimal:2'];
    }

    public function composition(): BelongsTo
    {
        return $this->belongsTo(ReferenceComposition::class, 'composition_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(ReferenceResource::class, 'resource_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(ReferenceComposition::class, 'child_composition_id');
    }
}
