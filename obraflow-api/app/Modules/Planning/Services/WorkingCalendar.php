<?php

namespace App\Modules\Planning\Services;

use App\Models\Project;
use App\Models\ProjectCalendarException;
use App\Modules\Projects\Services\ProjectCalendar;
use App\Modules\Projects\Services\WorkScheduleService;
use Carbon\CarbonImmutable;

/**
 * Dias úteis da obra (D-P66): dia da semana com horário de trabalho (aba Horários; herdado da empresa) E fora das
 * exceções do calendário (feriados, paradas, fins de semana importados). Obra sem horário definido: todo dia da
 * semana é útil (só as exceções tiram dias). As exceções são expandidas por ano, sob demanda (cache por instância).
 */
final class WorkingCalendar
{
    /** @var array<int, bool> dia da semana (0 = domingo) => trabalha */
    private array $weekdays;

    /** @var array<int, array<string, true>> ano => datas de exceção */
    private array $exceptions = [];

    /** @var list<ProjectCalendarException> */
    private array $rules;

    private const MAX_SEARCH = 3660; // 10 anos sem dia útil = calendário inválido

    public function __construct(private readonly Project $project, private readonly ProjectCalendar $calendar, WorkScheduleService $schedules, bool $calendarDays = false)
    {
        if ($calendarDays) { // A-51: dias corridos — todo dia conta (feriados só aparecem destacados na tela)
            $this->weekdays = array_fill(0, 7, true);
            $this->rules = [];

            return;
        }
        $week = $schedules->forProject($project);
        $days = [];
        foreach ($week['days'] as $d) {
            $days[$d['weekday']] = $week['source'] === 'none' || $d['hours'] !== '0:00';
        }
        // horário definido mas sem nenhum dia de trabalho: não trava o cronograma (todo dia útil)
        $this->weekdays = in_array(true, $days, true) ? $days : array_fill(0, 7, true);
        $this->rules = ProjectCalendarException::query()->where('project_id', $project->id)->get()->all();
    }

    /** Dias úteis da obra (D-P66). */
    public static function for(Project $project): self
    {
        return new self($project, app(ProjectCalendar::class), app(WorkScheduleService::class));
    }

    /** Conforme o planejamento: `calendar` = dias corridos; `working` = dias úteis da obra (A-51). */
    public static function forMode(Project $project, string $mode): self
    {
        return new self($project, app(ProjectCalendar::class), app(WorkScheduleService::class), $mode !== 'working');
    }

    public function isWorking(CarbonImmutable $day): bool
    {
        return $this->weekdays[$day->dayOfWeek] && ! isset($this->exceptionsOf($day->year)[$day->toDateString()]);
    }

    /** Primeiro dia útil a partir de $day (inclusive). */
    public function next(CarbonImmutable $day): CarbonImmutable
    {
        for ($i = 0; $i < self::MAX_SEARCH; $i++, $day = $day->addDay()) {
            if ($this->isWorking($day)) {
                return $day;
            }
        }
        throw new \RuntimeException('Calendário da obra sem dias úteis.');
    }

    /** Último dia útil até $day (inclusive). */
    public function previous(CarbonImmutable $day): CarbonImmutable
    {
        for ($i = 0; $i < self::MAX_SEARCH; $i++, $day = $day->subDay()) {
            if ($this->isWorking($day)) {
                return $day;
            }
        }
        throw new \RuntimeException('Calendário da obra sem dias úteis.');
    }

    /** Término de uma atividade que começa em $start e dura $days dias úteis (contando o início). */
    public function finish(CarbonImmutable $start, int $days): CarbonImmutable
    {
        $day = $this->next($start);
        for ($left = max(1, $days) - 1; $left > 0; $left--) {
            $day = $this->next($day->addDay());
        }

        return $day;
    }

    /** Dias úteis entre duas datas (inclusive). */
    public function count(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $n = 0;
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $n += $this->isWorking($d) ? 1 : 0;
        }

        return $n;
    }

    /** @return array<string, true> */
    private function exceptionsOf(int $year): array
    {
        if (! isset($this->exceptions[$year])) {
            $from = CarbonImmutable::create($year, 1, 1);
            $to = CarbonImmutable::create($year, 12, 31);
            $set = [];
            foreach ($this->rules as $rule) {
                // no cronograma, recorrente sem "até" continua valendo além do término previsto: se a obra atrasar,
                // os fins de semana e paradas recorrentes seguem fora dos dias úteis (sem limite = fim do período)
                foreach ($this->calendar->occurrences($rule, $from, $to, null) as $date) {
                    $set[$date] = true;
                }
            }
            $this->exceptions[$year] = $set;
        }

        return $this->exceptions[$year];
    }
}
