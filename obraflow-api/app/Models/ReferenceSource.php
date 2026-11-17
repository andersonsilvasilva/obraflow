<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fonte de referência técnica (ex.: SINAPI). Global (D-P29). */
class ReferenceSource extends Model
{
    protected $fillable = ['code', 'name', 'publisher'];

    public function datasets(): HasMany
    {
        return $this->hasMany(ReferenceDataset::class, 'source_id');
    }
}
