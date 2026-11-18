<?php

namespace App\Modules\Budgets\Report;

/**
 * Gráfico de Pareto da curva ABC em SVG puro (sem JavaScript): barras do valor de cada linha (até 25; o restante
 * vira "Demais"), linha do % acumulado e as faixas A (até 80%), B (até 95%) e C. O PDF usa o SVG direto; o DOCX e o
 * XLSX usam um PNG capturado do mesmo SVG pelo Chrome — os três mostram o mesmo gráfico.
 */
final class AbcChart
{
    public const WIDTH = 760;

    public const HEIGHT = 300;

    private const MAX_BARS = 25;

    /** @param list<array<string, mixed>> $rows linhas da AbcCurve (já ordenadas) */
    public static function svg(array $rows, string $title): string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;
        $left = 64;
        $right = 44;
        $top = 34;
        $bottom = 46;
        $pw = $w - $left - $right;
        $ph = $h - $top - $bottom;

        $bars = array_slice($rows, 0, self::MAX_BARS);
        $rest = array_slice($rows, self::MAX_BARS);
        $points = array_map(fn ($r) => ['label' => (string) $r['code'], 'value' => (float) $r['total'], 'cum' => (float) $r['cumulative_percent'], 'class' => $r['class']], $bars);
        if ($rest !== []) {
            $points[] = ['label' => 'Demais', 'value' => array_sum(array_map(fn ($r) => (float) $r['total'], $rest)), 'cum' => 100.0, 'class' => 'C'];
        }

        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $out = [];
        $out[] = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$w} {$h}\" width=\"{$w}\" height=\"{$h}\" font-family=\"Geist, Arial, sans-serif\">";
        $out[] = "<rect width=\"{$w}\" height=\"{$h}\" fill=\"#ffffff\"/>";
        $out[] = "<text x=\"{$left}\" y=\"20\" font-size=\"13\" font-weight=\"600\" fill=\"#0a2e66\">{$e($title)}</text>";

        if ($points === []) {
            $out[] = '<text x="'.($w / 2).'" y="'.($h / 2).'" text-anchor="middle" font-size="12" fill="#6b7280">Sem dados para a curva ABC.</text></svg>';

            return implode('', $out);
        }

        $max = max(array_column($points, 'value')) ?: 1.0;
        $n = count($points);
        $slot = $pw / $n;
        $barW = max(4.0, min(28.0, $slot * 0.68));
        $y = fn (float $pct) => $top + $ph - ($pct / 100) * $ph; // eixo do % (direita) e das barras (proporcional)

        // faixas A/B/C (linhas de 80% e 95% no eixo acumulado)
        foreach ([80 => 'A ≤ 80%', 95 => 'B ≤ 95%'] as $p => $label) {
            $yy = round($y($p), 1);
            $out[] = "<line x1=\"{$left}\" x2=\"".($left + $pw)."\" y1=\"{$yy}\" y2=\"{$yy}\" stroke=\"#9ca3af\" stroke-dasharray=\"4 4\" stroke-width=\"1\"/>";
            $out[] = '<text x="'.($left + $pw - 4).'" y="'.($yy - 4)."\" text-anchor=\"end\" font-size=\"10\" fill=\"#6b7280\">{$label}</text>";
        }
        // eixos
        $out[] = "<line x1=\"{$left}\" x2=\"{$left}\" y1=\"{$top}\" y2=\"".($top + $ph).'" stroke="#d1d5db"/>';
        $out[] = "<line x1=\"{$left}\" x2=\"".($left + $pw).'" y1="'.($top + $ph).'" y2="'.($top + $ph).'" stroke="#d1d5db"/>';
        foreach ([0, 25, 50, 75, 100] as $p) {
            $yy = round($y($p), 1);
            $out[] = '<text x="'.($left + $pw + 6).'" y="'.($yy + 3)."\" font-size=\"10\" fill=\"#6b7280\">{$p}%</text>";
            $out[] = '<text x="'.($left - 6).'" y="'.($yy + 3).'" text-anchor="end" font-size="10" fill="#6b7280">'.$e(self::short($max * $p / 100)).'</text>';
        }

        // barras
        $colors = ['A' => '#0f52ba', 'B' => '#5b8def', 'C' => '#b8cdf5'];
        $line = [];
        foreach ($points as $i => $pt) {
            $cx = $left + $slot * $i + $slot / 2;
            $bh = ($pt['value'] / $max) * $ph;
            $out[] = '<rect x="'.round($cx - $barW / 2, 1).'" y="'.round($top + $ph - $bh, 1).'" width="'.round($barW, 1).'" height="'.round(max($bh, 0.5), 1)."\" rx=\"2\" fill=\"{$colors[$pt['class']]}\"/>";
            $line[] = round($cx, 1).','.round($y($pt['cum']), 1);
            if ($n <= 26) {
                $lx = round($cx, 1);
                $ly = $top + $ph + 12;
                $out[] = "<text x=\"{$lx}\" y=\"{$ly}\" font-size=\"9\" fill=\"#374151\" text-anchor=\"end\" transform=\"rotate(-40 {$lx} {$ly})\">".$e(mb_strimwidth($pt['label'], 0, 10, '…')).'</text>';
            }
        }
        // % acumulado
        $out[] = '<polyline points="'.implode(' ', $line).'" fill="none" stroke="#f59e0b" stroke-width="2.2" stroke-linejoin="round"/>';
        foreach ($line as $p) {
            [$px, $py] = explode(',', $p);
            $out[] = "<circle cx=\"{$px}\" cy=\"{$py}\" r=\"2.4\" fill=\"#f59e0b\"/>";
        }
        // legenda
        $lx = $left + 260;
        foreach (['A' => 'Faixa A', 'B' => 'Faixa B', 'C' => 'Faixa C'] as $k => $label) {
            $out[] = "<rect x=\"{$lx}\" y=\"11\" width=\"10\" height=\"10\" rx=\"2\" fill=\"{$colors[$k]}\"/><text x=\"".($lx + 14)."\" y=\"20\" font-size=\"10\" fill=\"#374151\">{$label}</text>";
            $lx += 66;
        }
        $out[] = "<line x1=\"{$lx}\" x2=\"".($lx + 16).'" y1="16" y2="16" stroke="#f59e0b" stroke-width="2.2"/><text x="'.($lx + 20).'" y="20" font-size="10" fill="#374151">% acumulado</text>';
        $out[] = '</svg>';

        return implode('', $out);
    }

    /** HTML mínimo só com o gráfico (para a captura em PNG). */
    public static function page(string $svg): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><style>'.ReportAssets::fontFace().'html,body{margin:0;padding:0;background:#fff}svg{display:block}</style></head><body>'.$svg.'</body></html>';
    }

    /** Valor curto para o eixo: 1,2 mil · 3,4 mi. */
    private static function short(float $v): string
    {
        return match (true) {
            $v >= 1_000_000 => number_format($v / 1_000_000, 1, ',', '.').' mi',
            $v >= 1_000 => number_format($v / 1_000, 1, ',', '.').' mil',
            default => number_format($v, 0, ',', '.'),
        };
    }
}
