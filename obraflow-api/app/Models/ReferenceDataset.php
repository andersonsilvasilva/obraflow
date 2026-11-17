<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Base de referência: fonte + competência + UF + regime de encargos (D-P03, D-P29). Global; gravada só pelo importador. */
class ReferenceDataset extends Model
{
    public const REGIMES = ['with_relief', 'without_relief', 'not_applicable'];

    protected $fillable = ['source_id', 'version', 'state', 'location_name', 'charges_regime', 'status', 'activated_at'];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ReferenceSource::class, 'source_id');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(ReferenceImport::class, 'dataset_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(ReferenceResource::class, 'dataset_id');
    }

    public function compositions(): HasMany
    {
        return $this->hasMany(ReferenceComposition::class, 'dataset_id');
    }
}
