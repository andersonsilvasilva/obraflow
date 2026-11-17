<?php

namespace App\Modules\Budgets\Services;

use App\Models\BudgetVersion;
use App\Modules\Budgets\Support\Dec;
use Illuminate\Support\Facades\DB;

/**
 * Curva ABC calculada sob demanda (D-P33) — sem tabela. Faixas: A até 80% do valor acumulado, B até 95%, C o resto.
 * Serviços: itens pelo custo total. Insumos: consolidados por código a partir dos insumos finais de cada item
 * (quantidade = Σ quantidade do item × coeficiente; valor = arred₂(quantidade × preço)).
 */
final class AbcCurve
{
    public const LIMIT_A = '80';

    public const LIMIT_B = '95';

    /** @return list<array<string, mixed>> */
    public function services(BudgetVersion $version): array
    {
        $rows = DB::table('budget_items')->where('budget_version_id', $version->id)
            ->get(['id', 'code', 'description', 'unit_code', 'quantity', 'unit_cost', 'total_cost'])
            ->map(fn ($r) => ['code' => $r->code, 'description' => $r->description, 'unit' => $r->unit_code, 'quantity' => (string) $r->quantity,
                'unit_value' => (string) $r->unit_cost, 'total' => (string) $r->total_cost])
            ->all();

        return $this->classify($rows);
    }

    /** @return list<array<string, mixed>> */
    public function resources(BudgetVersion $version): array
    {
        $rows = DB::table('budget_item_resources as r')->join('budget_items as i', 'i.id', '=', 'r.budget_item_id')
            ->where('r.budget_version_id', $version->id)
            ->get(['r.kind', 'r.code', 'r.description', 'r.unit_code', 'r.coefficient', 'r.unit_price', 'i.quantity']);

        $byCode = [];
        foreach ($rows as $r) {
            $q = Dec::mul((string) $r->quantity, (string) $r->coefficient);
            $byCode[$r->code] ??= ['code' => $r->code, 'description' => $r->description, 'unit' => $r->unit_code, 'kind' => $r->kind,
                'quantity' => '0', 'unit_value' => (string) ($r->unit_price ?? '0')];
            $byCode[$r->code]['quantity'] = Dec::add($byCode[$r->code]['quantity'], $q);
        }
        foreach ($byCode as &$res) {
            $res['total'] = Dec::round(Dec::mul($res['quantity'], $res['unit_value']));
            $res['quantity'] = Dec::round($res['quantity'], 4);
        }

        return $this->classify(array_values($byCode));
    }

    /** Ordena pelo valor, calcula % e % acumulado (4 casas) e a faixa. */
    private function classify(array $rows): array
    {
        usort($rows, fn ($a, $b) => Dec::cmp($b['total'], $a['total']) ?: strcmp($a['code'], $b['code']));
        $grand = Dec::add(...array_column($rows, 'total') ?: ['0']);
        $acc = '0';
        foreach ($rows as &$row) {
            $pct = Dec::cmp($grand, '0') === 0 ? '0' : Dec::mul(Dec::div($row['total'], $grand), '100');
            $before = $acc;
            $acc = Dec::add($acc, $pct);
            $row['percent'] = Dec::round($pct, 4);
            $row['cumulative_percent'] = Dec::round($acc, 4);
            // a faixa é decidida por onde o item COMEÇA (o item que cruza 80% ainda é A)
            $row['class'] = Dec::cmp($before, self::LIMIT_A) < 0 ? 'A' : (Dec::cmp($before, self::LIMIT_B) < 0 ? 'B' : 'C');
        }

        return $rows;
    }
}
