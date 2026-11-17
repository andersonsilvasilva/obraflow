<?php

namespace App\Modules\Projects\Services;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Horário de trabalho (D-P63/D-P64). Um dono (empresa ou obra) tem 7 linhas quando o horário foi definido; a obra
 * SEM linhas próprias herda o padrão da empresa dela. Salvar substitui a semana inteira numa transação (com
 * auditoria); "voltar ao padrão" apaga as linhas da obra. Horas no formato H:MM.
 */
final class WorkScheduleService
{
    public const PERIODS = ['start_1', 'end_1', 'start_2', 'end_2'];

    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context) {}

    /** @return array{source: string, days: list<array<string, mixed>>, weekly_hours: string} */
    public function forCompany(Company $company): array
    {
        $rows = WorkSchedule::query()->where('company_id', $company->id)->get();

        return $this->present($rows->isEmpty() ? 'none' : 'company', $rows);
    }

    /** Horário efetivo da obra: o próprio (`project`) ou herdado da empresa (`company`); `none` se nenhum existe. */
    public function forProject(Project $project): array
    {
        $own = WorkSchedule::query()->where('project_id', $project->id)->get();
        if ($own->isNotEmpty()) {
            return $this->present('project', $own);
        }
        $inherited = WorkSchedule::query()->where('company_id', $project->company_id)->get();

        return $this->present($inherited->isEmpty() ? 'none' : 'company', $inherited);
    }

    /** @param list<array<string, string|null>> $days 7 dias (índice = dia da semana) */
    public function save(Model $owner, array $days, User $actor): void
    {
        $column = $owner instanceof Project ? 'project_id' : 'company_id';

        DB::transaction(function () use ($owner, $column, $days, $actor) {
            $owner->newQuery()->whereKey($owner->getKey())->lockForUpdate()->first();
            $old = $this->snapshot(WorkSchedule::query()->where($column, $owner->getKey())->get());
            WorkSchedule::query()->where($column, $owner->getKey())->delete();
            foreach (range(0, 6) as $weekday) {
                $row = new WorkSchedule(['weekday' => $weekday] + array_intersect_key($days[$weekday] ?? [], array_flip(self::PERIODS)));
                $row->forceFill([$column => $owner->getKey()])->save();
            }
            $new = $this->snapshot(WorkSchedule::query()->where($column, $owner->getKey())->get());

            $this->audit->record(AuditEvent::SCHEDULE_UPDATED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
                entityType: $owner->getMorphClass(), entityId: $owner->getKey(), old: $old ?: null, new: $new);
        });
    }

    /** A obra volta a herdar o horário da empresa. */
    public function reset(Project $project, User $actor): void
    {
        DB::transaction(function () use ($project, $actor) {
            $rows = WorkSchedule::query()->where('project_id', $project->id)->get();
            if ($rows->isEmpty()) {
                return;
            }
            WorkSchedule::query()->where('project_id', $project->id)->delete();
            $this->audit->record(AuditEvent::SCHEDULE_RESET, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
                entityType: 'project', entityId: $project->id, old: $this->snapshot($rows));
        });
    }

    /** @param iterable<WorkSchedule> $rows */
    private function present(string $source, iterable $rows): array
    {
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r->weekday] = $r;
        }
        $days = [];
        $weekMinutes = 0;
        foreach (range(0, 6) as $weekday) {
            $r = $byDay[$weekday] ?? null;
            $times = [];
            foreach (self::PERIODS as $p) {
                $times[$p] = $r?->{$p} ? substr((string) $r->{$p}, 0, 5) : null;
            }
            $minutes = $this->minutes($times['start_1'], $times['end_1']) + $this->minutes($times['start_2'], $times['end_2']);
            $weekMinutes += $minutes;
            $days[] = ['weekday' => $weekday, ...$times, 'hours' => $this->hm($minutes), 'defined' => $r !== null];
        }

        return ['source' => $source, 'days' => $days, 'weekly_hours' => $this->hm($weekMinutes)];
    }

    private function minutes(?string $start, ?string $end): int
    {
        if ($start === null || $end === null) {
            return 0;
        }
        [$h1, $m1] = array_map('intval', explode(':', $start));
        [$h2, $m2] = array_map('intval', explode(':', $end));

        return max(0, ($h2 * 60 + $m2) - ($h1 * 60 + $m1));
    }

    private function hm(int $minutes): string
    {
        return intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /** @param iterable<WorkSchedule> $rows @return array<int, string> dia => "07:00-12:00 13:00-16:00" */
    private function snapshot(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $p1 = $r->start_1 ? substr((string) $r->start_1, 0, 5).'-'.substr((string) $r->end_1, 0, 5) : '';
            $p2 = $r->start_2 ? substr((string) $r->start_2, 0, 5).'-'.substr((string) $r->end_2, 0, 5) : '';
            $out[$r->weekday] = trim("{$p1} {$p2}") ?: 'folga';
        }
        ksort($out);

        return $out;
    }
}
