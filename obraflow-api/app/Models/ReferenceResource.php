<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Insumo de uma base. `kind` vem da Macro-Classe publicada pela fonte (NULL se não informada). */
class ReferenceResource extends Model
{
    public const KINDS = ['material', 'labor', 'equipment', 'service'];

    protected $fillable = ['dataset_id', 'code', 'description', 'unit_id', 'kind', 'family_class'];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(ReferenceDataset::class, 'dataset_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ReferenceUnit::class, 'unit_id');
    }

    public function price(): HasOne
    {
        return $this->hasOne(ReferenceResourcePrice::class, 'resource_id');
    }
}
