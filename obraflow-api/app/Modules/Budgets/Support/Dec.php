<?php

namespace App\Modules\Budgets\Support;

/**
 * Aritmética decimal exata para orçamentos (D-P32), com bcmath: nada passa por float.
 * Arredondamento meio-para-cima (afastando do zero) na escala pedida.
 */
final class Dec
{
    private const WORK = 12; // casas usadas nos produtos intermediários

    public static function add(string ...$values): string
    {
        $sum = '0';
        foreach ($values as $v) {
            $sum = bcadd($sum, self::n($v), self::WORK);
        }

        return $sum;
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::n($a), self::n($b), self::WORK);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::n($a), self::n($b), self::WORK);
    }

    public static function div(string $a, string $b): string
    {
        return bcdiv(self::n($a), self::n($b), self::WORK);
    }

    /** Fator percentual: 25 → 1.25 (acréscimo) ou, com $minus, 25 → 0.75 (desconto). */
    public static function factor(string $percent, bool $minus = false): string
    {
        $p = bcdiv(self::n($percent), '100', self::WORK);

        return $minus ? bcsub('1', $p, self::WORK) : bcadd('1', $p, self::WORK);
    }

    public static function round(string $v, int $scale = 2): string
    {
        $v = self::n($v);
        $half = '0.'.str_repeat('0', $scale).'5';

        return str_starts_with($v, '-') ? bcsub($v, $half, $scale) : bcadd($v, $half, $scale);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp(self::n($a), self::n($b), self::WORK);
    }

    private static function n(string $v): string
    {
        $v = trim($v);

        return $v === '' ? '0' : $v;
    }
}
