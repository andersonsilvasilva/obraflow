<?php

namespace App\Modules\Registry\Concerns;

use App\Models\Company;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cadastros mestres com soft delete, uuid público e documento só com dígitos
 * (empresas, filiais, clientes, fornecedores). `active_marker` é coluna gerada: nunca é gravada.
 */
trait IsRegistryParty
{
    use BelongsToTenant, NormalizesDigits, SoftDeletes;

    protected function documentNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $v) => self::digits($v));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
