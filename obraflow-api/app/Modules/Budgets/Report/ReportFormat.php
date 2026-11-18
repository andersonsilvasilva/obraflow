<?php

namespace App\Modules\Budgets\Report;

/**
 * Formatação brasileira para os três formatos do relatório. Recebe os decimais como STRING (vindos do Dec) e
 * nunca passa por float: os números impressos são exatamente os calculados.
 */
final class ReportFormat
{
    /** "1234.5" → "R$ 1.234,50" */
    public static function money(?string $v): string
    {
        return 'R$ '.self::decimal($v ?? '0', 2);
    }

    /** Quantidade: até 4 casas, sem zeros à direita, mínimo 2 ("12.5000" → "12,50"; "3.3333" → "3,3333"). */
    public static function quantity(?string $v): string
    {
        return self::decimal($v ?? '0', 4, 2);
    }

    /** Coeficiente da composição: até 7 casas, mínimo 4. */
    public static function coefficient(?string $v): string
    {
        return self::decimal($v ?? '0', 7, 4);
    }

    /** "25.0000" → "25,00%" */
    public static function percent(?string $v, int $scale = 2): string
    {
        return self::decimal($v ?? '0', $scale).'%';
    }

    public static function cnpj(?string $digits): ?string
    {
        if ($digits === null || $digits === '') {
            return null;
        }
        if (strlen($digits) === 14) {
            return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8, 4).'-'.substr($digits, 12, 2);
        }
        if (strlen($digits) === 11) {
            return substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2);
        }

        return $digits;
    }

    /**
     * Arredonda meio-para-cima com $max casas, tira zeros à direita até $min casas e aplica separadores pt-BR.
     */
    public static function decimal(string $v, int $max, ?int $min = null): string
    {
        $min ??= $max;
        $neg = str_starts_with($v, '-');
        $v = ltrim($v, '-+');
        $rounded = bcadd($v, '0.'.str_repeat('0', $max).'5', $max); // meio-para-cima (valor absoluto)
        [$int, $frac] = array_pad(explode('.', $rounded), 2, '');
        $frac = rtrim($frac, '0');
        $frac = str_pad($frac, $min, '0');
        $int = ltrim($int, '0') ?: '0';
        $int = strrev(implode('.', str_split(strrev($int), 3)));
        $isZero = trim($int.$frac, '0.') === '';

        return ($neg && ! $isZero ? '-' : '').$int.($frac !== '' ? ','.$frac : '');
    }
}
