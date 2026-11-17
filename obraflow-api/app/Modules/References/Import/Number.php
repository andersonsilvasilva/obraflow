<?php

namespace App\Modules\References\Import;

/**
 * Converte números como publicados ("3,84", "1.234,56", "0,0046000", "0.2191") em texto decimal com escala fixa,
 * sem passar por float (evita perda de precisão). Vazio → null.
 */
final class Number
{
    public static function decimal(?string $value, int $scale = 2): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        if (str_contains($v, ',')) {
            $v = str_replace(['.', ','], ['', '.'], $v); // formato brasileiro
        }
        if (! preg_match('/^-?\d+(\.\d+)?([eE]-?\d+)?$/', $v)) {
            throw new \InvalidArgumentException("Número inválido: \"{$value}\"");
        }
        if (stripos($v, 'e') !== false) {
            $v = sprintf('%.'.($scale + 4).'F', (float) $v); // notação científica (raro em XLSX)
        }

        return self::round($v, $scale);
    }

    /** Arredondamento meio-para-cima com bcmath (soma meia unidade da última casa e trunca). */
    private static function round(string $v, int $scale): string
    {
        $offset = '0.'.str_repeat('0', $scale).'5';

        return str_starts_with($v, '-') ? bcsub($v, $offset, $scale) : bcadd($v, $offset, $scale);
    }
}
