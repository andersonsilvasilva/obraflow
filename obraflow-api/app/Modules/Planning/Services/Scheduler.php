<?php

namespace App\Modules\Planning\Services;

use App\Models\Project;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\ScheduleTask;
use App\Modules\Shared\Exceptions\DomainRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Motor do cronograma (D-P66/D-P67), em dias úteis da obra:
 * 1. reprograma as atividades e marcos pelas dependências (ASAP): FS, SS, FF, SF com atraso (negativo = adiantamento);
 *    atividade SEM predecessora mantém a data de início informada;
 * 2. calcula folga total e caminho crítico (CPM, ida e volta) — não gravados: devolvidos na consulta;
 * 3. etapas cobrem as filhas (início/término) e o % é a média ponderada pela duração;
 * 4. renumera a EAP (1, 1.1, 1.1.1…) pela ordem.
 *
 * Contas em índices de dia útil (0 = primeiro dia útil do planejamento). Atividade: EF = ES + duração − 1.
 * Marco: duração 0, EF = ES − 1 (acontece "no início" do dia ES).
 */
final class Scheduler
{
    /** @var list<CarbonImmutable> dia útil por índice */
    private array $days = [];

    /** @var array<string, int> data => índice do primeiro dia útil ≥ data */
    private array $index = [];

    private WorkingCalendar $calendar;

    /**
     * Recalcula e grava. @return array<int, array{float: int, critical: bool}> folga e caminho crítico por atividade
     */
    public function recompute(Schedule $schedule): array
    {
        $this->calendar = WorkingCalendar::for(Project::query()->findOrFail($schedule->project_id));
        $tasks = ScheduleTask::query()->where('schedule_id', $schedule->id)->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');
        $deps = ScheduleDependency::query()->where('schedule_id', $schedule->id)->get();
        if ($tasks->isEmpty()) {
            return [];
        }

        $leaves = $tasks->filter(fn ($t) => $t->kind !== 'summary');
        $order = $this->topological($leaves->keys()->all(), $deps);

        $start = $leaves->isEmpty() ? CarbonImmutable::parse($tasks->first()->planned_start) : CarbonImmutable::parse($leaves->min(fn ($t) => $t->planned_start->toDateString()));
        $this->days = [$this->calendar->next($start)];
        $this->index = [];

        $preds = $deps->groupBy('successor_id');
        $succs = $deps->groupBy('predecessor_id');
        $len = fn (ScheduleTask $t) => $t->kind === 'milestone' ? 0 : max(1, (int) $t->duration_days);
        $es = [];
        $ef = [];

        foreach ($order as $id) {
            $t = $leaves[$id];
            $l = $len($t);
            $bounds = [];
            foreach ($preds->get($id, collect()) as $d) {
                $p = $d->predecessor_id;
                $lag = (int) $d->lag_days;
                $bounds[] = match ($d->type) {
                    'FS' => $ef[$p] + 1 + $lag,
                    'SS' => $es[$p] + $lag,
                    'FF' => $ef[$p] + $lag - $l + 1,
                    'SF' => $es[$p] + $lag - $l + 1,
                };
            }
            $es[$id] = $bounds === [] ? $this->indexOf(CarbonImmutable::parse($t->planned_start)) : max(0, max($bounds));
            $ef[$id] = $es[$id] + $l - 1;
        }

        // volta: folga total
        $finish = $order === [] ? 0 : max(array_map(fn ($id) => max($ef[$id], $es[$id] - 1), $order));
        $lf = [];
        foreach (array_reverse($order) as $id) {
            $l = $len($leaves[$id]);
            $limit = $finish;
            foreach ($succs->get($id, collect()) as $d) {
                $s = $d->successor_id;
                $ls = $lf[$s] - $len($leaves[$s]) + 1;
                $lag = (int) $d->lag_days;
                $limit = min($limit, match ($d->type) {
                    'FS' => $ls - 1 - $lag,
                    'SS' => $ls - $lag + $l - 1,
                    'FF' => $lf[$s] - $lag,
                    'SF' => $lf[$s] - $lag + $l - 1,
                });
            }
            $lf[$id] = $limit;
        }

        $info = [];
        $updates = [];
        foreach ($order as $id) {
            $t = $leaves[$id];
            $l = $len($t);
            $startDate = $this->dateAt($es[$id]);
            $endDate = $l === 0 ? $startDate : $this->dateAt($ef[$id]);
            $float = ($lf[$id] - $l + 1) - $es[$id];
            $info[$id] = ['float' => $float, 'critical' => $float <= 0];
            $updates[$id] = ['planned_start' => $startDate->toDateString(), 'planned_end' => $endDate->toDateString()];
        }

        $this->rollUp($tasks, $updates);
        $this->renumber($tasks, $updates);
        $this->persist($tasks, $updates);

        return $info;
    }

    /** Recusa a dependência que fecharia um ciclo (antes de gravar). */
    public function assertNoCycle(Schedule $schedule, int $predecessor, int $successor): void
    {
        $deps = ScheduleDependency::query()->where('schedule_id', $schedule->id)->get(['predecessor_id', 'successor_id']);
        $next = [];
        foreach ($deps as $d) {
            $next[$d->predecessor_id][] = $d->successor_id;
        }
        // existe caminho sucessor → … → predecessor?
        $stack = [$successor];
        $seen = [];
        while ($stack !== []) {
            $n = array_pop($stack);
            if ($n === $predecessor) {
                throw new DomainRuleException('Essa dependência criaria um ciclo entre as atividades.');
            }
            if (isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            array_push($stack, ...($next[$n] ?? []));
        }
    }

    /** @param list<int> $ids @return list<int> ordem topológica (Kahn); ciclo → erro */
    private function topological(array $ids, $deps): array
    {
        $in = array_fill_keys($ids, 0);
        $next = [];
        foreach ($deps as $d) {
            if (isset($in[$d->successor_id], $in[$d->predecessor_id])) {
                $in[$d->successor_id]++;
                $next[$d->predecessor_id][] = $d->successor_id;
            }
        }
        $queue = array_keys(array_filter($in, fn ($n) => $n === 0));
        $out = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            $out[] = $id;
            foreach ($next[$id] ?? [] as $s) {
                if (--$in[$s] === 0) {
                    $queue[] = $s;
                }
            }
        }
        if (count($out) !== count($ids)) {
            throw new DomainRuleException('As dependências formam um ciclo.');
        }

        return $out;
    }

    /** Etapas cobrem as filhas (de baixo para cima); % ponderado pela duração das atividades. */
    private function rollUp($tasks, array &$updates): void
    {
        $children = [];
        foreach ($tasks as $t) {
            $children[$t->parent_id ?? 0][] = $t->id;
        }
        $visit = function (int $id) use (&$visit, $tasks, $children, &$updates): array {
            $t = $tasks[$id];
            $start = $updates[$id]['planned_start'] ?? $t->planned_start->toDateString();
            $end = $updates[$id]['planned_end'] ?? $t->planned_end->toDateString();
            if ($t->kind !== 'summary') {
                $w = $t->kind === 'milestone' ? 0 : max(1, (int) $t->duration_days);

                return [$start, $end, $w, $w * (float) $t->percent_complete];
            }
            $min = null;
            $max = null;
            $weight = 0;
            $done = 0.0;
            foreach ($children[$id] ?? [] as $c) {
                [$s, $e, $w, $p] = $visit($c);
                $min = $min === null || $s < $min ? $s : $min;
                $max = $max === null || $e > $max ? $e : $max;
                $weight += $w;
                $done += $p;
            }
            if ($min === null) { // etapa vazia: mantém as próprias datas
                $min = $start;
                $max = $end;
            }
            $percent = $weight > 0 ? round($done / $weight, 2) : 0.0;
            $updates[$id] = ['planned_start' => $min, 'planned_end' => $max, 'percent_complete' => $percent,
                'duration_days' => $this->calendar->count(CarbonImmutable::parse($min), CarbonImmutable::parse($max))];

            return [$min, $max, $weight, $percent * $weight];
        };
        foreach ($children[0] ?? [] as $root) {
            $visit($root);
        }
    }

    private function renumber($tasks, array &$updates): void
    {
        $children = [];
        foreach ($tasks as $t) {
            $children[$t->parent_id ?? 0][] = $t->id;
        }
        $walk = function (int $parent, string $prefix) use (&$walk, $children, &$updates): void {
            foreach ($children[$parent] ?? [] as $i => $id) {
                $code = $prefix === '' ? (string) ($i + 1) : $prefix.'.'.($i + 1);
                $updates[$id]['wbs_code'] = $code;
                $walk($id, $code);
            }
        };
        $walk(0, '');
    }

    private function persist($tasks, array $updates): void
    {
        $now = now();
        foreach ($updates as $id => $u) {
            $t = $tasks[$id];
            $dirty = array_filter($u, function ($v, $k) use ($t) {
                $cur = $t->{$k};

                return $cur instanceof \DateTimeInterface ? $cur->format('Y-m-d') !== $v : (string) $cur !== (string) $v;
            }, ARRAY_FILTER_USE_BOTH);
            if ($dirty !== []) {
                DB::table('schedule_tasks')->where('id', $id)->update($dirty + ['updated_at' => $now]);
            }
        }
    }

    private function indexOf(CarbonImmutable $date): int
    {
        $key = $date->toDateString();
        if (isset($this->index[$key])) {
            return $this->index[$key];
        }
        if ($date->lt($this->days[0])) {
            return 0;
        }
        while (end($this->days)->lt($date)) {
            $this->days[] = $this->calendar->next(end($this->days)->addDay());
        }
        $i = 0;
        foreach ($this->days as $k => $d) { // primeiro dia útil ≥ data
            if ($d->gte($date)) {
                $i = $k;
                break;
            }
        }

        return $this->index[$key] = $i;
    }

    private function dateAt(int $i): CarbonImmutable
    {
        $i = max(0, $i);
        while (count($this->days) <= $i) {
            $this->days[] = $this->calendar->next(end($this->days)->addDay());
        }

        return $this->days[$i];
    }
}
