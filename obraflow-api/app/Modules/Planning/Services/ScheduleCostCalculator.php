<?php

namespace App\Modules\Planning\Services;

use App\Models\Schedule;
use App\Models\ScheduleTask;
use App\Models\ScheduleTaskItem;
use App\Modules\Budgets\Support\Dec;

/**
 * Único lugar que calcula custo e venda do planejamento (6B, D-P72): a mesma conta do orçamento, em aritmética
 * decimal exata (bcmath), arredondando POR RECURSO e somando os arredondados (D-P32). Só leitura: nada é gravado.
 *
 * Por recurso:
 *   quantidade total = quantidade da atividade (sem quantidade = 1) × consumo por unidade (D-P91)
 *   custo  = arred₂(quantidade total × custo unitário)
 *   com BDI = arred₂(custo × (1 + BDI%))                BDI e desconto: do recurso, senão do planejamento (D-P92)
 *   venda  = arred₂(com BDI × (1 − desconto%))
 *   por tipo: cada parcela (material, mão de obra, equipamento, serviço, outros) é arredondada; a diferença de
 *   centavos fica na maior parcela, para que os tipos somem exatamente o total do recurso.
 * Atividade = soma dos recursos; etapa = soma das filhas; planejamento = soma das atividades.
 * Unitários da atividade (colunas do sintético) = arred₂(Σ consumo × custo unitário), por tipo, e × fatores para a venda.
 */
final class ScheduleCostCalculator
{
    public const KINDS = ScheduleTaskItem::COST_KINDS;

    /**
     * @param  iterable<ScheduleTask>|null  $tasks  atividades já carregadas (evita nova consulta)
     * @return array{items: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function compute(Schedule $schedule, ?iterable $tasks = null): array
    {
        $tasks = collect($tasks ?? ScheduleTask::query()->where('schedule_id', $schedule->id)->get());
        $byId = $tasks->keyBy('id');
        $items = ScheduleTaskItem::query()->where('schedule_id', $schedule->id)->orderBy('sort_order')->orderBy('id')->get();
        $bdi = (string) ($schedule->bdi_percent ?? '0');
        $discount = (string) ($schedule->discount_percent ?? '0');

        $outItems = [];
        $own = []; // somas por atividade (só os próprios recursos)
        foreach ($items as $it) {
            $task = $byId->get($it->task_id);
            if (! $task) {
                continue;
            }
            $line = $this->line($it, $task, $bdi, $discount);
            $outItems[$it->id] = $line;
            $own[$it->task_id] = $this->add($own[$it->task_id] ?? $this->zero(), $line, $it);
        }

        // etapa = soma das filhas (subindo na árvore)
        $sum = [];
        foreach ($tasks as $t) {
            $sum[$t->id] = $this->zero();
        }
        foreach ($own as $taskId => $s) {
            for ($id = $taskId; $id !== null; $id = $byId->get($id)?->parent_id) {
                $sum[$id] = $this->merge($sum[$id] ?? $this->zero(), $s);
            }
        }

        $outTasks = [];
        foreach ($tasks as $t) {
            $outTasks[$t->id] = $this->finish($sum[$t->id], $t, $own[$t->id] ?? null);
        }

        $roots = $tasks->whereNull('parent_id');
        $total = $this->zero();
        foreach ($roots as $r) {
            $total = $this->merge($total, $sum[$r->id]);
        }

        return [
            'items' => $outItems,
            'tasks' => $outTasks,
            'totals' => [
                'cost' => Dec::round($total['cost']),
                'with_bdi' => Dec::round($total['with_bdi']),
                'bdi_amount' => Dec::round(Dec::sub($total['with_bdi'], $total['cost'])),
                'discount_amount' => Dec::round(Dec::sub($total['with_bdi'], $total['sale'])),
                'sale' => Dec::round($total['sale']),
                'bdi_percent' => $bdi,
                'discount_percent' => $discount,
                'by_kind' => array_map(fn ($k) => ['cost' => Dec::round($total['kinds'][$k]['cost']), 'sale' => Dec::round($total['kinds'][$k]['sale'])], array_combine(self::KINDS, self::KINDS)),
                'item_count' => count($outItems),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function line(ScheduleTaskItem $it, ScheduleTask $task, string $scheduleBdi, string $scheduleDiscount): array
    {
        $taskQty = $task->quantity !== null && Dec::cmp((string) $task->quantity, '0') > 0 ? (string) $task->quantity : '1';
        $qty = Dec::mul($taskQty, (string) $it->quantity_per_unit);
        $bdi = $it->bdi_percent !== null ? (string) $it->bdi_percent : $scheduleBdi;
        $discount = $it->discount_percent !== null ? (string) $it->discount_percent : $scheduleDiscount;
        $fb = Dec::factor($bdi);
        $fd = Dec::factor($discount, minus: true);

        $cost = Dec::round(Dec::mul($qty, (string) $it->unit_cost));
        $withBdi = Dec::round(Dec::mul($cost, $fb));
        $sale = Dec::round(Dec::mul($withBdi, $fd));

        $kindCost = $this->split(array_map(fn ($k) => Dec::mul($qty, (string) $it->{"cost_{$k}"}), array_combine(self::KINDS, self::KINDS)), $cost);
        $kindSale = $this->split(array_map(fn ($c) => Dec::mul(Dec::mul($c, $fb), $fd), $kindCost), $sale);

        return [
            'task_id' => $it->task_id,
            'quantity_total' => Dec::round($qty, 4),
            'bdi_percent' => $bdi,
            'discount_percent' => $discount,
            'unit_sale' => Dec::round(Dec::mul(Dec::mul((string) $it->unit_cost, $fb), $fd)),
            'cost' => $cost,
            'with_bdi' => $withBdi,
            'sale' => $sale,
            'kinds' => array_map(fn ($k) => ['cost' => $kindCost[$k], 'sale' => $kindSale[$k]], array_combine(self::KINDS, self::KINDS)),
            // para os unitários da atividade
            '_unit' => array_map(fn ($k) => Dec::mul((string) $it->quantity_per_unit, (string) $it->{"cost_{$k}"}), array_combine(self::KINDS, self::KINDS)),
            '_factor' => Dec::mul($fb, $fd),
        ];
    }

    /**
     * Arredonda as parcelas e põe a diferença de centavos na maior, para somarem exatamente $total.
     *
     * @param  array<string, string>  $raw
     * @return array<string, string>
     */
    private function split(array $raw, string $total): array
    {
        $out = array_map(fn ($v) => Dec::round($v), $raw);
        $diff = Dec::sub($total, Dec::add('0', ...array_values($out)));
        if (Dec::cmp($diff, '0') !== 0) {
            $largest = array_keys($raw, max($raw))[0];
            foreach ($raw as $k => $v) {
                if (Dec::cmp($v, $raw[$largest]) > 0) {
                    $largest = $k;
                }
            }
            $out[$largest] = Dec::round(Dec::add($out[$largest], $diff));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function zero(): array
    {
        return ['cost' => '0.00', 'with_bdi' => '0.00', 'sale' => '0.00', 'count' => 0,
            'kinds' => array_fill_keys(self::KINDS, ['cost' => '0.00', 'sale' => '0.00']),
            'unit' => array_fill_keys(self::KINDS, '0'), 'unit_sale' => array_fill_keys(self::KINDS, '0')];
    }

    private function add(array $acc, array $line, ScheduleTaskItem $it): array
    {
        $acc['cost'] = Dec::add($acc['cost'], $line['cost']);
        $acc['with_bdi'] = Dec::add($acc['with_bdi'], $line['with_bdi']);
        $acc['sale'] = Dec::add($acc['sale'], $line['sale']);
        $acc['count']++;
        foreach (self::KINDS as $k) {
            $acc['kinds'][$k]['cost'] = Dec::add($acc['kinds'][$k]['cost'], $line['kinds'][$k]['cost']);
            $acc['kinds'][$k]['sale'] = Dec::add($acc['kinds'][$k]['sale'], $line['kinds'][$k]['sale']);
            $acc['unit'][$k] = Dec::add($acc['unit'][$k], $line['_unit'][$k]);
            $acc['unit_sale'][$k] = Dec::add($acc['unit_sale'][$k], Dec::mul($line['_unit'][$k], $line['_factor']));
        }

        return $acc;
    }

    private function merge(array $a, array $b): array
    {
        $a['cost'] = Dec::add($a['cost'], $b['cost']);
        $a['with_bdi'] = Dec::add($a['with_bdi'], $b['with_bdi']);
        $a['sale'] = Dec::add($a['sale'], $b['sale']);
        $a['count'] += $b['count'];
        foreach (self::KINDS as $k) {
            $a['kinds'][$k]['cost'] = Dec::add($a['kinds'][$k]['cost'], $b['kinds'][$k]['cost']);
            $a['kinds'][$k]['sale'] = Dec::add($a['kinds'][$k]['sale'], $b['kinds'][$k]['sale']);
        }

        return $a;
    }

    /** Totais da atividade/etapa; unitários só para atividade com recursos próprios. */
    private function finish(array $s, ScheduleTask $t, ?array $own): array
    {
        $out = [
            'cost' => Dec::round($s['cost']),
            'with_bdi' => Dec::round($s['with_bdi']),
            'sale' => Dec::round($s['sale']),
            'item_count' => $s['count'],
            'kinds' => array_map(fn ($v) => ['cost' => Dec::round($v['cost']), 'sale' => Dec::round($v['sale'])], $s['kinds']),
            'unit_cost' => null,
            'unit_sale' => null,
            'unit_kinds' => null,
        ];
        if ($own !== null && $t->kind === 'task') {
            $uk = array_map(fn ($k) => ['cost' => Dec::round($own['unit'][$k]), 'sale' => Dec::round($own['unit_sale'][$k])], array_combine(self::KINDS, self::KINDS));
            $out['unit_kinds'] = $uk;
            $out['unit_cost'] = Dec::round(Dec::add('0', ...array_column($uk, 'cost')));
            $out['unit_sale'] = Dec::round(Dec::add('0', ...array_column($uk, 'sale')));
        }

        return $out;
    }
}
