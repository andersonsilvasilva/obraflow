<?php

namespace App\Modules\Budgets\Services;

use App\Modules\Budgets\Support\Dec;
use App\Modules\Shared\Exceptions\DomainRuleException;

/**
 * Fórmula do TCU (Acórdão 2622/2013) para o BDI, opcional (D-P02):
 *
 *   BDI = [ (1 + AC + S + R + G) × (1 + DF) × (1 + L) / (1 − I) ] − 1
 *
 * AC administração central · S seguro · R risco · G garantia · DF despesas financeiras · L lucro · I tributos
 * (todos em %). Resultado em % com 2 casas. Validar o uso em obra privada é decisão do responsável (FASE4_MODELO §5).
 */
final class BdiFormula
{
    public const COMPONENTS = ['ac', 's', 'r', 'g', 'df', 'l', 'i'];

    /** @param array<string, string|int|float> $components percentuais @return string BDI em % (2 casas) */
    public static function tcu(array $components): string
    {
        $p = [];
        foreach (self::COMPONENTS as $key) {
            $v = (string) ($components[$key] ?? '0');
            if (! is_numeric($v) || Dec::cmp($v, '0') < 0) {
                throw new DomainRuleException("Componente do BDI inválido: {$key}.");
            }
            $p[$key] = Dec::div($v, '100');
        }
        if (Dec::cmp($p['i'], '1') >= 0) {
            throw new DomainRuleException('Tributos (I) precisam ser menores que 100%.');
        }

        $num = Dec::mul(Dec::mul(Dec::add('1', $p['ac'], $p['s'], $p['r'], $p['g']), Dec::add('1', $p['df'])), Dec::add('1', $p['l']));
        $bdi = Dec::sub(Dec::div($num, Dec::sub('1', $p['i'])), '1');

        return Dec::round(Dec::mul($bdi, '100'), 2);
    }
}
