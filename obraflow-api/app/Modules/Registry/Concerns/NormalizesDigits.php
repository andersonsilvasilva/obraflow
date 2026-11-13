<?php

namespace App\Modules\Registry\Concerns;

/** CPF/CNPJ/CEP são gravados só com dígitos (máscara é apresentação). */
trait NormalizesDigits
{
    protected static function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits === '' ? null : $digits;
    }
}
