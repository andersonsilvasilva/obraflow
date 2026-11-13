<?php

namespace App\Modules\Registry\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CPF/CNPJ com dígitos verificadores (aceita com ou sem máscara). Validar os dígitos NÃO prova que o documento
 * existe na Receita Federal (FASE2_MODELO §6).
 */
final class TaxDocument implements ValidationRule
{
    /** @param 'cpf'|'cnpj' $kind */
    public function __construct(private readonly string $kind) {}

    public static function forType(?string $type): self
    {
        return new self($type === 'person' ? 'cpf' : 'cnpj');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        $valid = $this->kind === 'cpf' ? self::isCpf($digits) : self::isCnpj($digits);
        if (! $valid) {
            $fail($this->kind === 'cpf' ? 'CPF inválido.' : 'CNPJ inválido.');
        }
    }

    public static function isCpf(string $d): bool
    {
        if (! preg_match('/^\d{11}$/', $d) || preg_match('/^(\d)\1{10}$/', $d)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $d[$i] * (($t + 1) - $i);
            }
            $check = ((10 * $sum) % 11) % 10;
            if ((int) $d[$t] !== $check) {
                return false;
            }
        }

        return true;
    }

    public static function isCnpj(string $d): bool
    {
        if (! preg_match('/^\d{14}$/', $d) || preg_match('/^(\d)\1{13}$/', $d)) {
            return false;
        }
        foreach ([12, 13] as $n) {
            $sum = 0;
            $weight = $n - 7;
            for ($i = 0; $i < $n; $i++) {
                $sum += (int) $d[$i] * $weight--;
                if ($weight < 2) {
                    $weight = 9;
                }
            }
            $check = $sum % 11 < 2 ? 0 : 11 - $sum % 11;
            if ((int) $d[$n] !== $check) {
                return false;
            }
        }

        return true;
    }
}
