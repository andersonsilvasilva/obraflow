<?php

namespace App\Modules\Planning\Services;

use App\Models\Budget;
use App\Models\BudgetVersion;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\ScheduleBaseline;
use App\Models\ScheduleDependency;
use App\Models\ScheduleProgress;
use App\Models\ScheduleTask;
use App\Models\ScheduleTaskItem;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Shared\Exceptions\DomainRuleException;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Escrita do planejamento (Fase 6A). Toda mudança roda numa transação e termina com o Scheduler (reprogramação,
 * etapas, EAP). Regras: um planejamento por obra; etapa só agrupa (sem duração própria nem dependências); até 6
 * níveis; dependência só entre atividades/marcos do mesmo planejamento e sem ciclo; avanço manual com histórico.
 */
final class ScheduleService
{
    public const MAX_DEPTH = 6;

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly RegistryService $registry,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function createBlank(Project $project, User $actor): Schedule
    {
        return DB::transaction(fn () => $this->newSchedule($project, 'blank', null, $actor));
    }

    /**
     * A partir da versão APROVADA do orçamento da obra (D-P68): etapas → etapas; itens → atividades (quantidade,
     * unidade e vínculo com o item). Começam todas na data inicial da obra com 1 dia útil — o usuário liga e ajusta.
     */
    public function createFromBudget(Project $project, BudgetVersion $version, User $actor): Schedule
    {
        $budget = Budget::query()->findOrFail($version->budget_id);
        if ($version->status !== 'approved' || ! $version->isLocked()) {
            throw new DomainRuleException('Use uma versão APROVADA do orçamento.');
        }
        if ((int) $budget->project_id !== (int) $project->id) {
            throw new DomainRuleException('O orçamento deve ser desta obra.');
        }

        return DB::transaction(function () use ($project, $version, $actor) {
            $schedule = $this->newSchedule($project, 'budget', $version->id, $actor, recompute: false);
            $start = $this->startOf($project);
            $calendar = WorkingCalendar::forMode($project, $schedule->duration_mode ?? 'calendar');
            $first = $calendar->next($start)->toDateString();
            $now = now();

            $groups = DB::table('budget_groups')->where('budget_version_id', $version->id)->orderBy('sort_order')->orderBy('id')->get();
            $map = [];
            $pending = $groups->all();
            $guard = 0;
            while ($pending !== [] && $guard++ < 20) { // por nível: pai antes do filho
                foreach ($pending as $k => $g) {
                    if ($g->parent_id !== null && ! isset($map[$g->parent_id])) {
                        continue;
                    }
                    $map[$g->id] = DB::table('schedule_tasks')->insertGetId([
                        'tenant_id' => $schedule->tenant_id, 'schedule_id' => $schedule->id, 'parent_id' => $g->parent_id ? $map[$g->parent_id] : null,
                        'wbs_code' => $g->code, 'name' => Str::limit($g->name, 250, ''), 'kind' => 'summary', 'planned_start' => $first, 'planned_end' => $first,
                        'duration_days' => 1, 'sort_order' => $g->sort_order, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    unset($pending[$k]);
                }
            }

            $rows = [];
            foreach (DB::table('budget_items')->where('budget_version_id', $version->id)->orderBy('sort_order')->orderBy('id')->get() as $i) {
                $rows[] = [
                    'tenant_id' => $schedule->tenant_id, 'schedule_id' => $schedule->id, 'parent_id' => $map[$i->group_id] ?? null,
                    'wbs_code' => $i->code, 'name' => Str::limit($i->description, 250, ''), 'kind' => 'task', 'unit_code' => $i->unit_code,
                    'quantity' => $i->quantity, 'planned_start' => $first, 'planned_end' => $first, 'duration_days' => 1,
                    'budget_item_id' => $i->id, 'sort_order' => $i->sort_order, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('schedule_tasks')->insert($chunk);
            }
            $this->scheduler->recompute($schedule);
            app(ScheduleItemService::class)->fromBudget($schedule, $actor, copyBdi: true); // 6B (D-P93): custos dos itens

            return $schedule;
        });
    }

    /** "Deletar tudo": apaga o planejamento (atividade com avanço registrado impede — histórico preservado). */
    public function delete(Schedule $schedule, User $actor): void
    {
        DB::transaction(function () use ($schedule, $actor) {
            $tasks = ScheduleTask::query()->where('schedule_id', $schedule->id)->pluck('id');
            if (ScheduleProgress::query()->whereIn('task_id', $tasks)->exists()) {
                throw new DomainRuleException('Há atividades com avanço registrado: o planejamento não pode ser apagado.');
            }
            if (ScheduleBaseline::query()->where('schedule_id', $schedule->id)->exists()) {
                throw new DomainRuleException('O planejamento tem linha de base: não pode ser apagado (a linha de base é histórico).');
            }
            ScheduleDependency::query()->where('schedule_id', $schedule->id)->delete();
            ScheduleTaskItem::query()->where('schedule_id', $schedule->id)->delete(); // recursos (6B)
            // filhos antes dos pais (FK RESTRICT)
            while (($ids = ScheduleTask::query()->where('schedule_id', $schedule->id)
                ->whereNotIn('id', ScheduleTask::query()->select('parent_id')->where('schedule_id', $schedule->id)->whereNotNull('parent_id'))
                ->pluck('id'))->isNotEmpty()) {
                ScheduleTask::query()->whereIn('id', $ids)->delete();
            }
            $count = $tasks->count();
            $schedule->delete();
            $this->audit->record(AuditEvent::SCHEDULE_DELETED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
                entityType: 'project', entityId: $schedule->project_id, metadata: ['tasks' => $count, 'origin' => $schedule->origin]);
        });
    }

    /** Como contar a duração (A-51): dias corridos ou úteis. As durações ficam; as datas são recalculadas. */
    public function setDurationMode(Schedule $schedule, string $mode, User $actor): void
    {
        DB::transaction(function () use ($schedule, $mode, $actor) {
            $schedule->forceFill(['duration_mode' => $mode]);
            $this->registry->update($schedule, [], $actor);
            $this->scheduler->recompute($schedule);
        });
    }

    // ------------------------------------------------------------ atividades

    public function addTask(Schedule $schedule, array $data, User $actor): ScheduleTask
    {
        return DB::transaction(function () use ($schedule, $data, $actor) {
            $parent = isset($data['parent_id']) ? $this->task($schedule, (int) $data['parent_id']) : null;
            if ($parent !== null) {
                $this->assertDepth($schedule, $parent);
                $this->becomeSummary($parent);
            }
            $kind = $data['kind'] ?? 'task';
            [$start, $end, $duration] = $this->dates($schedule, $kind, $data, $parent);

            $task = new ScheduleTask;
            $task->forceFill([
                'schedule_id' => $schedule->id, 'parent_id' => $parent?->id, 'wbs_code' => '0', 'kind' => $kind,
                'planned_start' => $start, 'planned_end' => $end, 'duration_days' => $duration,
                'sort_order' => (int) ScheduleTask::query()->where('schedule_id', $schedule->id)->where('parent_id', $parent?->id)->max('sort_order') + 1,
            ] + array_intersect_key($data, array_flip(['name', 'unit_code', 'quantity'])));
            $this->registry->create($task, [], $actor);
            $this->scheduler->recompute($schedule);

            return $task->refresh();
        });
    }

    public function updateTask(Schedule $schedule, ScheduleTask $task, array $data, User $actor): ScheduleTask
    {
        return DB::transaction(function () use ($schedule, $task, $data, $actor) {
            $changes = array_intersect_key($data, array_flip(['name', 'unit_code', 'quantity', 'actual_start', 'actual_end']));
            if ($task->kind !== 'summary' && (isset($data['planned_start']) || isset($data['duration_days']) || isset($data['planned_end']))) {
                [$start, $end, $duration] = $this->dates($schedule, $task->kind, $data + ['planned_start' => $task->planned_start->toDateString(),
                    'duration_days' => isset($data['planned_end']) ? null : $task->duration_days], null);
                $changes += ['planned_start' => $start, 'planned_end' => $end, 'duration_days' => $duration];
            }
            if (array_key_exists('percent_complete', $data)) {
                if ($task->kind === 'summary') {
                    throw new DomainRuleException('O avanço da etapa é calculado pelas atividades dela.');
                }
                $changes['percent_complete'] = $data['percent_complete'];
            }
            $old = (string) $task->percent_complete;
            $task->forceFill($changes);
            $this->registry->update($task, [], $actor);
            if (array_key_exists('percent_complete', $changes) && (float) $old !== (float) $changes['percent_complete']) {
                $p = new ScheduleProgress;
                $p->forceFill(['task_id' => $task->id, 'percent_complete' => $changes['percent_complete'], 'source' => 'manual', 'recorded_by' => $actor->getKey()])->save();
            }
            $this->scheduler->recompute($schedule);

            return $task->refresh();
        });
    }

    /** Mover: outro pai (etapa) e/ou posição entre os irmãos. */
    public function moveTask(Schedule $schedule, ScheduleTask $task, ?int $parentId, int $position, User $actor): void
    {
        DB::transaction(function () use ($schedule, $task, $parentId, $position, $actor) {
            $parent = $parentId !== null ? $this->task($schedule, $parentId) : null;
            for ($p = $parent; $p !== null; $p = $p->parent_id ? ScheduleTask::query()->find($p->parent_id) : null) {
                if ($p->id === $task->id) {
                    throw new DomainRuleException('Uma etapa não pode ficar dentro dela mesma.');
                }
            }
            if ($parent !== null) {
                $this->assertDepth($schedule, $parent);
                $this->becomeSummary($parent);
            }
            $siblings = ScheduleTask::query()->where('schedule_id', $schedule->id)->where('parent_id', $parent?->id)->whereKeyNot($task->id)
                ->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
            array_splice($siblings, max(0, min($position, count($siblings))), 0, [$task->id]);
            foreach ($siblings as $i => $id) {
                DB::table('schedule_tasks')->where('id', $id)->update(['sort_order' => $i + 1] + ($id === $task->id ? ['parent_id' => $parent?->id] : []));
            }
            $this->audit->record(AuditEvent::REGISTRY_UPDATED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
                entityType: 'schedule_task', entityId: $task->id, old: ['parent_id' => $task->parent_id], new: ['parent_id' => $parent?->id, 'position' => $position]);
            $this->scheduler->recompute($schedule);
        });
    }

    /** Exclui a atividade (e, se for etapa, tudo abaixo dela), com as dependências. */
    public function deleteTask(Schedule $schedule, ScheduleTask $task, User $actor): void
    {
        DB::transaction(function () use ($schedule, $task, $actor) {
            $ids = $this->subtree($schedule, $task->id);
            if (ScheduleProgress::query()->whereIn('task_id', $ids)->exists()) {
                throw new DomainRuleException('Atividade com avanço registrado não pode ser excluída (o histórico é preservado).');
            }
            ScheduleDependency::query()->where('schedule_id', $schedule->id)
                ->where(fn ($q) => $q->whereIn('predecessor_id', $ids)->orWhereIn('successor_id', $ids))->delete();
            foreach (ScheduleTaskItem::query()->where('schedule_id', $schedule->id)->whereIn('task_id', $ids)->get() as $item) {
                $this->registry->delete($item, $actor); // recursos da atividade (6B)
            }
            foreach (array_reverse($ids) as $id) {
                $this->registry->delete(ScheduleTask::query()->findOrFail($id), $actor);
            }
            $this->scheduler->recompute($schedule);
        });
    }

    // ------------------------------------------------------------ dependências

    public function addDependency(Schedule $schedule, array $data, User $actor): ScheduleDependency
    {
        return DB::transaction(function () use ($schedule, $data, $actor) {
            $pred = $this->task($schedule, (int) $data['predecessor_id']);
            $succ = $this->task($schedule, (int) $data['successor_id']);
            if ($pred->kind === 'summary' || $succ->kind === 'summary') {
                throw new DomainRuleException('Dependências ligam atividades e marcos (não etapas).');
            }
            $this->scheduler->assertNoCycle($schedule, $pred->id, $succ->id);
            $dep = new ScheduleDependency;
            $dep->forceFill(['schedule_id' => $schedule->id, 'predecessor_id' => $pred->id, 'successor_id' => $succ->id,
                'type' => $data['type'] ?? 'FS', 'lag_days' => (int) ($data['lag_days'] ?? 0)]);
            $this->registry->create($dep, [], $actor);
            $this->scheduler->recompute($schedule);

            return $dep;
        });
    }

    public function deleteDependency(Schedule $schedule, ScheduleDependency $dep, User $actor): void
    {
        DB::transaction(function () use ($schedule, $dep, $actor) {
            $this->registry->delete($dep, $actor);
            $this->scheduler->recompute($schedule);
        });
    }

    // ------------------------------------------------------------ linhas de base

    public function createBaseline(Schedule $schedule, string $name, User $actor): ScheduleBaseline
    {
        return DB::transaction(function () use ($schedule, $name, $actor) {
            Schedule::query()->whereKey($schedule->id)->lockForUpdate()->first();
            $number = (int) ScheduleBaseline::query()->where('schedule_id', $schedule->id)->max('number') + 1;
            if ($number > ScheduleBaseline::MAX) {
                throw new DomainRuleException('Limite de '.ScheduleBaseline::MAX.' linhas de base atingido.');
            }
            $snapshot = ScheduleTask::query()->where('schedule_id', $schedule->id)->orderBy('id')->get()
                ->mapWithKeys(fn ($t) => [$t->id => ['wbs' => $t->wbs_code, 'name' => $t->name, 'start' => $t->planned_start->toDateString(),
                    'end' => $t->planned_end->toDateString(), 'duration' => $t->duration_days]])->all();
            $baseline = new ScheduleBaseline;
            $baseline->forceFill(['schedule_id' => $schedule->id, 'number' => $number, 'name' => $name, 'snapshot' => $snapshot, 'created_by' => $actor->getKey()])->save();
            $this->audit->record(AuditEvent::SCHEDULE_BASELINE_CREATED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
                entityType: 'project', entityId: $schedule->project_id, metadata: ['number' => $number, 'name' => $name, 'tasks' => count($snapshot)]);

            return $baseline;
        });
    }

    // ------------------------------------------------------------ apoio

    private function newSchedule(Project $project, string $origin, ?int $versionId, User $actor, bool $recompute = true): Schedule
    {
        if (Schedule::query()->where('project_id', $project->id)->exists()) {
            throw new DomainRuleException('A obra já tem planejamento. Para recomeçar, apague o atual ("Deletar tudo").');
        }
        $schedule = new Schedule;
        $schedule->forceFill(['uuid' => (string) Str::uuid7(), 'project_id' => $project->id, 'origin' => $origin, 'duration_mode' => 'calendar',
            'budget_version_id' => $versionId, 'created_by' => $actor->getKey()])->save();
        $this->audit->record(AuditEvent::SCHEDULE_CREATED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
            entityType: 'project', entityId: $project->id, metadata: array_filter(['origin' => $origin, 'budget_version_id' => $versionId]));

        return $schedule;
    }

    private function startOf(Project $project): CarbonImmutable
    {
        return CarbonImmutable::parse($project->start_date ?? now()->toDateString());
    }

    /** @return array{0: string, 1: string, 2: int} início, término e duração (dias úteis) conforme o tipo */
    private function dates(Schedule $schedule, string $kind, array $data, ?ScheduleTask $parent): array
    {
        $project = Project::query()->findOrFail($schedule->project_id);
        $calendar = WorkingCalendar::forMode($project, (string) ($schedule->duration_mode ?? 'calendar'));
        $start = $calendar->next(CarbonImmutable::parse($data['planned_start'] ?? $parent?->planned_start?->toDateString() ?? $this->startOf($project)->toDateString()));
        if ($kind === 'milestone') {
            return [$start->toDateString(), $start->toDateString(), 0];
        }
        if ($kind === 'summary') {
            return [$start->toDateString(), $start->toDateString(), 1];
        }
        if (! empty($data['planned_end']) && empty($data['duration_days'])) {
            $end = CarbonImmutable::parse($data['planned_end']);
            if ($end->lt($start)) {
                throw new DomainRuleException('O término não pode ser antes do início.');
            }
            $duration = max(1, $calendar->count($start, $end));
        } else {
            $duration = max(1, (int) ($data['duration_days'] ?? 1));
        }

        return [$start->toDateString(), $calendar->finish($start, $duration)->toDateString(), $duration];
    }

    /** Uma atividade que recebe filhas vira etapa (não pode ter dependências nem avanço próprio). */
    private function becomeSummary(ScheduleTask $task): void
    {
        if ($task->kind === 'summary') {
            return;
        }
        if (ScheduleDependency::query()->where('predecessor_id', $task->id)->orWhere('successor_id', $task->id)->exists()) {
            throw new DomainRuleException("\"{$task->name}\" tem dependências: remova-as antes de criar subetapas nela.");
        }
        if (ScheduleProgress::query()->where('task_id', $task->id)->exists()) {
            throw new DomainRuleException("\"{$task->name}\" tem avanço registrado: não pode virar etapa.");
        }
        if (ScheduleTaskItem::query()->where('task_id', $task->id)->exists()) {
            throw new DomainRuleException("\"{$task->name}\" tem recursos lançados: remova-os antes de criar sub atividades nela (a etapa soma as filhas).");
        }
        DB::table('schedule_tasks')->where('id', $task->id)->update(['kind' => 'summary', 'percent_complete' => 0, 'updated_at' => now()]);
        $task->kind = 'summary';
    }

    private function assertDepth(Schedule $schedule, ScheduleTask $parent): void
    {
        $depth = 1;
        for ($p = $parent; $p->parent_id !== null; $p = $this->task($schedule, $p->parent_id)) {
            $depth++;
        }
        if ($depth >= self::MAX_DEPTH) {
            throw new DomainRuleException('Limite de '.self::MAX_DEPTH.' níveis no planejamento.');
        }
    }

    public function task(Schedule $schedule, int $id): ScheduleTask
    {
        return ScheduleTask::query()->where('schedule_id', $schedule->id)->whereKey($id)->first()
            ?? throw new DomainRuleException('Atividade não pertence a este planejamento.');
    }

    /** @return list<int> a atividade e todas abaixo dela (pais antes dos filhos) */
    private function subtree(Schedule $schedule, int $id): array
    {
        $out = [$id];
        for ($i = 0; $i < count($out); $i++) {
            array_push($out, ...ScheduleTask::query()->where('schedule_id', $schedule->id)->where('parent_id', $out[$i])->pluck('id')->all());
        }

        return $out;
    }
}
