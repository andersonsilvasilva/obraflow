<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Preço publicado do insumo na base (competência, UF e regime fixados pela base). Nunca editado. */
class ReferenceResourcePrice extends Model
{
    protected $fillable = ['resource_id', 'price', 'price_origin'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(ReferenceResource::class, 'resource_id');
    }
}
