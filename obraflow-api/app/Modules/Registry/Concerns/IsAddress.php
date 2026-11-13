<?php

namespace App\Modules\Registry\Concerns;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Comportamento comum dos endereços dedicados (D-P12). O modelo que usa o trait declara a relação com o dono
 * e a coluna do dono em $fillable. `main_marker` é coluna gerada: nunca é gravada.
 */
trait IsAddress
{
    use BelongsToTenant, NormalizesDigits;

    public const KINDS = ['main', 'billing', 'delivery', 'site'];

    protected function initializeIsAddress(): void
    {
        $this->mergeFillable(['kind', 'street', 'number', 'complement', 'district', 'city', 'state', 'postal_code', 'latitude', 'longitude']);
        $this->mergeCasts(['latitude' => 'decimal:7', 'longitude' => 'decimal:7']);
    }

    protected function postalCode(): Attribute
    {
        return Attribute::make(set: fn (?string $v) => self::digits($v));
    }

    protected function state(): Attribute
    {
        return Attribute::make(set: fn (?string $v) => $v === null ? null : strtoupper(trim($v)));
    }
}
