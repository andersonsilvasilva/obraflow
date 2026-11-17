<?php

namespace App\Modules\Projects\Services;

use App\Models\Project;
use App\Models\ProjectCalendarException;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Shared\Exceptions\DomainRuleException;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Calendário da obra (CALENDARIO_OBRA_MODELO.md):
 * - ocorrências das exceções num período: única = a própria data; recorrente = data + k × N unidades até o "até"
 *   (sem "até": término previsto da obra; sem término, o fim do período pedido) — D-P58. Mês/ano não "transbordam":
 *   dia 31 cai no último dia dos meses curtos;
 * - dias da semana sem horário (horário efetivo da obra) — D-P64;
 * - importações: sábados e domingos como 2 exceções semanais (D-P59) e feriados nacionais do ano (D-P60).
 */
final class ProjectCalendar
{
    /** Maior período consultado de uma vez (o calendário pede um mês/semana/dia). */
    public const MAX_DAYS = 400;

    public function __construct(
        private readonly WorkScheduleService $schedules,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{from: string, to: string, days: list<array{date: string, description: string, exception: string, kind: string}>, non_working_weekdays: list<int>}
     */
    public function between(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($to->lt($from) || $from->diffInDays($to) > self::MAX_DAYS) {
            throw new DomainRuleException('Período inválido: informe "de" e "até" com no máximo '.self::MAX_DAYS.' dias.');
        }
        $projectEnd = $project->expected_end_date ? CarbonImmutable::parse($project->expected_end_date) : null;

        $days = [];
        $exceptions = ProjectCalendarException::query()->where('project_id', $project->id)->where('date', '<=', $to->toDateString())->orderBy('date')->get();
        foreach ($exceptions as $e) {
            foreach ($this->occurrences($e, $from, $to, $projectEnd) as $date) {
                $days[] = ['date' => $date, 'description' => $e->description, 'exception' => $e->uuid, 'kind' => $e->kind];
            }
        }
        usort($days, fn ($a, $b) => [$a['date'], $a['description']] <=> [$b['date'], $b['description']]);

        $week = $this->schedules->forProject($project)['days'];

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            // só quando há horário definido (obra ou empresa); sem horário, nenhum dia é presumido de folga
            'non_working_weekdays' => array_values(array_map(fn ($d) => $d['weekday'], array_filter($week, fn ($d) => $d['hours'] === '0:00' && $d['defined']))),
        ];
    }

    /** @return list<string> datas (AAAA-MM-DD) da exceção dentro de [from, to] */
    public function occurrences(ProjectCalendarException $e, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $projectEnd = null): array
    {
        $start = CarbonImmutable::parse($e->date)->startOfDay();
        if ($e->kind === 'single') {
            return $start->betweenIncluded($from, $to) ? [$start->toDateString()] : [];
        }

        $limit = $e->repeat_until ? CarbonImmutable::parse($e->repeat_until) : ($projectEnd ?? $to);
        $last = $limit->lt($to) ? $limit : $to;
        if ($last->lt($from) || $last->lt($start)) {
            return [];
        }

        $every = max(1, (int) $e->repeat_every);
        $stepDays = ['day' => 1, 'week' => 7, 'fortnight' => 14][$e->repeat_unit] ?? null;
        $out = [];

        if ($stepDays !== null) {
            $step = $stepDays * $every;
            $k = $from->gt($start) ? intdiv((int) $start->diffInDays($from) + $step - 1, $step) : 0;
            for ($d = $start->addDays($k * $step); $d->lte($last); $d = $d->addDays($step)) {
                $out[] = $d->toDateString();
            }

            return $out;
        }

        $months = $e->repeat_unit === 'year' ? 12 * $every : $every;
        $k = $from->gt($start) ? max(0, intdiv((int) $start->diffInMonths($from), $months) - 1) : 0;
        for (; ; $k++) {
            $d = $start->addMonthsNoOverflow($k * $months);
            if ($d->gt($last)) {
                break;
            }
            if ($d->gte($from)) {
                $out[] = $d->toDateString();
            }
        }

        return $out;
    }

    /** Sábados e domingos como duas exceções recorrentes semanais (D-P59). Não duplica. @return int criadas */
    public function importWeekends(Project $project, User $actor): int
    {
        $base = CarbonImmutable::parse($project->start_date ?? now()->toDateString());

        return $this->import($project, $actor, 'weekends', [
            ['description' => 'Sábado', 'date' => $this->firstOnOrAfter($base, CarbonImmutable::SATURDAY), 'weekday' => 6],
            ['description' => 'Domingo', 'date' => $this->firstOnOrAfter($base, CarbonImmutable::SUNDAY), 'weekday' => 0],
        ]);
    }

    /** Feriados nacionais do ano (D-P60), como exceções únicas. Não duplica datas já marcadas. @return int criadas */
    public function importHolidays(Project $project, int $year, User $actor): int
    {
        $rows = array_map(fn ($date, $name) => ['description' => $name, 'date' => $date], array_keys(self::nationalHolidays($year)), self::nationalHolidays($year));

        return $this->import($project, $actor, 'holidays', $rows, $year);
    }

    /**
     * Feriados nacionais (Lei 662/1949, 6.802/1980, 14.759/2023) e pontos facultativos nacionais ligados à Páscoa.
     *
     * @return array<string, string> data => nome
     */
    public static function nationalHolidays(int $year): array
    {
        $easter = self::easter($year);
        $list = [
            "{$year}-01-01" => 'Confraternização Universal',
            $easter->subDays(48)->toDateString() => 'Carnaval (ponto facultativo)',
            $easter->subDays(47)->toDateString() => 'Carnaval (ponto facultativo)',
            $easter->subDays(2)->toDateString() => 'Sexta-feira Santa',
            "{$year}-04-21" => 'Tiradentes',
            "{$year}-05-01" => 'Dia do Trabalho',
            $easter->addDays(60)->toDateString() => 'Corpus Christi (ponto facultativo)',
            "{$year}-09-07" => 'Independência do Brasil',
            "{$year}-10-12" => 'Nossa Senhora Aparecida',
            "{$year}-11-02" => 'Finados',
            "{$year}-11-15" => 'Proclamação da República',
            "{$year}-12-25" => 'Natal',
        ];
        if ($year >= 2024) {
            $list["{$year}-11-20"] = 'Dia Nacional de Zumbi e da Consciência Negra';
        }
        ksort($list);

        return $list;
    }

    /** Domingo de Páscoa (algoritmo de Meeus/Jones/Butcher, calendário gregoriano). */
    public static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }

    /** Primeira data no dia da semana pedido, a partir de $base (inclusive). */
    private function firstOnOrAfter(CarbonImmutable $base, int $weekday): string
    {
        return ($base->dayOfWeek === $weekday ? $base : $base->next($weekday))->toDateString();
    }

    /** @param list<array{description: string, date: string, weekday?: int}> $rows */
    private function import(Project $project, User $actor, string $what, array $rows, ?int $year = null): int
    {
        return DB::transaction(function () use ($project, $actor, $what, $rows, $year) {
            Project::query()->whereKey($project->id)->lockForUpdate()->first(); // importações concorrentes não duplicam
            $existing = ProjectCalendarException::query()->where('project_id', $project->id)->get();
            $created = 0;

            foreach ($rows as $row) {
                $duplicate = isset($row['weekday'])
                    ? $existing->contains(fn ($e) => $e->kind === 'recurring' && $e->repeat_unit === 'week' && (int) $e->repeat_every === 1
                        && CarbonImmutable::parse($e->date)->dayOfWeek === $row['weekday'])
                    : $existing->contains(fn ($e) => $e->kind === 'single' && $e->date->toDateString() === $row['date']);
                if ($duplicate) {
                    continue;
                }
                $exception = new ProjectCalendarException(isset($row['weekday'])
                    ? ['description' => $row['description'], 'date' => $row['date'], 'kind' => 'recurring', 'repeat_every' => 1, 'repeat_unit' => 'week']
                    : ['description' => $row['description'], 'date' => $row['date'], 'kind' => 'single']);
                $exception->forceFill(['project_id' => $project->id, 'created_by' => $actor->getKey()])->save();
                $created++;
            }

            $this->audit->record(AuditEvent::CALENDAR_IMPORTED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
                entityType: 'project', entityId: $project->id, metadata: array_filter(['what' => $what, 'year' => $year, 'created' => $created], fn ($v) => $v !== null));

            return $created;
        });
    }
}
