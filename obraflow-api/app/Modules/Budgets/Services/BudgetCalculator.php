<?php

namespace App\Modules\Budgets\Services;

use App\Models\BudgetItem;
use App\Models\BudgetVersion;
use App\Modules\Budgets\Exceptions\VersionLockedException;
use App\Modules\Budgets\Support\Dec;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Único lugar que calcula valores do orçamento (D-P02, D-P32, D-P33). Aritmética decimal exata (bcmath).
 *
 * Por item:
 *   custo  = arred2(quantidade × custo unitário)
 *   venda  = arred2(custo × (1 + BDI%) × (1 − desconto do item%))   — desconto incide sobre o PREÇO DE VENDA
 * Versão:
 *   preço antes do desconto global = Σ vendas dos itens + custos indiretos (indiretos fora do BDI)
 *   desconto global = arred2(preço × desconto%)      preço final = preço − desconto global
 * Arredonda POR ITEM e soma os itens arredondados (D-P32). Grava `total_cost`/`total_sale` e `totals_snapshot`.
 */
final class BudgetCalculator
{
    public function __construct(private readonly BdiResolver $bdi) {}

    /** @return array<string, mixed> o snapshot gravado */
    public function recalculate(BudgetVersion $version): array
    {
        $version->refresh();
        if ($version->isLocked()) {
            throw new VersionLockedException;
        }

        return DB::transaction(function () use ($version) {
            ['snapshot' => $snapshot, 'items' => $lines, 'models' => $items] = $this->compute($version);

            foreach ($items as $item) {
                $line = $lines[$item->id];
                if (Dec::cmp($line['total_cost'], (string) $item->total_cost) !== 0 || Dec::cmp($line['total_sale'], (string) $item->total_sale) !== 0) {
                    // escrita direta: o bloqueio da versão já foi conferido acima
                    DB::table('budget_items')->where('id', $item->id)->update(['total_cost' => $line['total_cost'], 'total_sale' => $line['total_sale'], 'updated_at' => now()]);
                }
            }

            DB::table('budget_versions')->where('id', $version->id)->update(['totals_snapshot' => json_encode($snapshot), 'updated_at' => now()]);
            $version->setRawAttributes(array_merge($version->getAttributes(), ['totals_snapshot' => json_encode($snapshot)]), true);

            return $snapshot;
        });
    }

    /**
     * O mesmo cálculo, SÓ LEITURA (vale também para versão bloqueada): usado pelo relatório (4D), para que os
     * números sejam exatamente os da tela.
     *
     * @return array{snapshot: array<string, mixed>, items: array<int, array{bdi_percent: string, total_cost: string, with_bdi: string, total_sale: string}>, models: Collection<int, BudgetItem>}
     */
    public function compute(BudgetVersion $version): array
    {
        $items = BudgetItem::query()->where('budget_version_id', $version->id)->get();
        $resolve = $this->bdi->for($version);

        $direct = '0';
        $beforeDiscount = '0';
        $itemsSale = '0';
        $byGroup = [];
        $lines = [];

        foreach ($items as $item) {
            $bdi = $resolve($item);
            $cost = Dec::round(Dec::mul((string) $item->quantity, (string) $item->unit_cost));
            $withBdi = Dec::round(Dec::mul($cost, Dec::factor($bdi)));
            $sale = Dec::round(Dec::mul($withBdi, Dec::factor((string) $item->discount_percent, minus: true)));
            $lines[$item->id] = ['bdi_percent' => $bdi, 'total_cost' => $cost, 'with_bdi' => $withBdi, 'total_sale' => $sale];

            $direct = Dec::add($direct, $cost);
            $beforeDiscount = Dec::add($beforeDiscount, $withBdi);
            $itemsSale = Dec::add($itemsSale, $sale);
            $byGroup[$item->group_id]['cost'] = Dec::add($byGroup[$item->group_id]['cost'] ?? '0', $cost);
            $byGroup[$item->group_id]['sale'] = Dec::add($byGroup[$item->group_id]['sale'] ?? '0', $sale);
        }

        $indirect = $this->indirect($version, $direct);
        $price = Dec::add($itemsSale, $indirect);
        $globalDiscount = Dec::round(Dec::mul($price, Dec::div((string) $version->discount_percent, '100')));
        $final = Dec::sub($price, $globalDiscount);

        $snapshot = [
            'direct_cost' => Dec::round($direct),
            'indirect_cost' => Dec::round($indirect),
            'total_cost' => Dec::round(Dec::add($direct, $indirect)),
            'bdi_amount' => Dec::round(Dec::sub($beforeDiscount, $direct)),
            'items_discount' => Dec::round(Dec::sub($beforeDiscount, $itemsSale)),
            'items_sale' => Dec::round($itemsSale),
            'global_discount_percent' => (string) $version->discount_percent,
            'global_discount' => $globalDiscount,
            'final_price' => Dec::round($final),
            'groups' => $this->rollUp($version, $byGroup),
            'item_count' => $items->count(),
            'calculated_at' => now()->toIso8601String(),
        ];

        return ['snapshot' => $snapshot, 'items' => $lines, 'models' => $items];
    }

    /** Custos indiretos: valor fixo ou % do custo direto, cada linha arredondada (D-P02). */
    private function indirect(BudgetVersion $version, string $direct): string
    {
        $total = '0';
        foreach (DB::table('budget_indirect_costs')->where('budget_version_id', $version->id)->get() as $row) {
            $total = Dec::add($total, $row->method === 'fixed'
                ? (string) $row->amount
                : Dec::round(Dec::mul($direct, Dec::div((string) $row->percent, '100'))));
        }

        return $total;
    }

    /**
     * Subtotais por etapa INCLUINDO as subetapas. @param array<int, array{cost:string, sale:string}> $own
     *
     * @return array<int, array{cost:string, sale:string}>
     */
    private function rollUp(BudgetVersion $version, array $own): array
    {
        $parents = DB::table('budget_groups')->where('budget_version_id', $version->id)->pluck('parent_id', 'id')->all();
        $totals = [];
        foreach ($parents as $id => $_) {
            $totals[$id] = ['cost' => '0', 'sale' => '0'];
        }
        foreach ($own as $groupId => $sum) {
            for ($g = $groupId; $g !== null; $g = $parents[$g] ?? null) {
                $totals[$g]['cost'] = Dec::add($totals[$g]['cost'] ?? '0', $sum['cost']);
                $totals[$g]['sale'] = Dec::add($totals[$g]['sale'] ?? '0', $sum['sale']);
            }
        }

        return array_map(fn ($t) => ['cost' => Dec::round($t['cost']), 'sale' => Dec::round($t['sale'])], $totals);
    }
}
