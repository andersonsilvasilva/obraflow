<?php

namespace App\Modules\Planning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetVersion;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\ScheduleBaseline;
use App\Models\ScheduleDependency;
use App\Models\ScheduleTask;
use App\Models\ScheduleTaskItem;
use App\Modules\Budgets\Services\BudgetAccess;
use App\Modules\Planning\Http\Requests\ScheduleRequest;
use App\Modules\Planning\Import\ScheduleSheetImporter;
use App\Modules\Planning\Services\ScheduleCostCalculator;
use App\Modules\Planning\Services\ScheduleItemService;
use App\Modules\Planning\Services\Scheduler;
use App\Modules\Planning\Services\ScheduleService;
use App\Modules\Projects\Services\ProjectAccess;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Shared\Exceptions\DomainRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Planejamento da obra (Fase 6A). Acesso: permissão `schedules.*` na rota E o acesso por obra (D-P24): ver exige ver a
 * obra; alterar exige nível `edit`. Obra invisível → 404. Toda resposta devolve o cronograma inteiro já recalculado.
 */
class ScheduleController extends Controller
{
    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly Scheduler $scheduler,
        private readonly ProjectAccess $access,
        private readonly ScheduleItemService $items,
        private readonly ScheduleCostCalculator $costs,
    ) {}

    public function show(Request $request, string $uuid): JsonResponse
    {
        $project = $this->project($request, $uuid, 'view');

        return $this->respond($request, $project, $request->integer('baseline') ?: null);
    }

    /** Cria: em branco ou do orçamento aprovado da obra (D-P68; planilha na etapa 2). */
    public function store(ScheduleRequest $request, string $uuid): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        if ($request->validated('origin') === 'budget') {
            $budget = Budget::query()->where('uuid', $request->validated('budget_uuid'))->firstOrFail();
            if (! app(BudgetAccess::class)->allows($request->user(), $budget, 'view')) {
                throw ApiException::notFound();
            }
            $version = BudgetVersion::query()->where('budget_id', $budget->id)->where('version_number', $request->validated('version'))->firstOrFail();
            $this->schedules->createFromBudget($project, $version, $request->user());
        } else {
            $this->schedules->createBlank($project, $request->user());
        }

        return $this->respond($request, $project, null, 201);
    }

    /** Configuração do planejamento: como contar a duração (A-51). */
    public function update(ScheduleRequest $request, string $uuid): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        $this->schedules->setDurationMode($this->schedule($project), $request->validated('duration_mode'), $request->user());

        return $this->respond($request, $project);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        $this->schedules->delete($this->schedule($project), $request->user());

        return response()->json(null, 204);
    }

    public function storeTask(ScheduleRequest $request, string $uuid): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        $this->schedules->addTask($this->schedule($project), $request->validated(), $request->user());

        return $this->respond($request, $project, null, 201);
    }

    public function updateTask(ScheduleRequest $request, string $uuid, int $id): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        $schedule = $this->schedule($project);
        $this->schedules->updateTask($schedule, $this->schedules->task($schedule, $id), $request->validated(), $request->user());

        return $this->respond($request, $project);
    }

    public function moveTask(ScheduleRequest $request, string $uuid, int $id): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        $schedule = $this->schedule($project);
        $this->schedules->moveTask($schedule, $this->schedules->task($schedule, $id), $request->validated('parent_id'), (int) $request->validated('position'), $request->user());

        return $this->respond($request, $project);
    }

    public function destroyTask(Request $request, string $uuid, int $id): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        $schedule = $this->schedule($project);
        $this->schedules->deleteTask($schedule, $this->schedules->task($schedule, $id), $request->user());

        return $this->respond($request, $project);
    }

    public function storeDependency(ScheduleRequest $request, string $uuid): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        $this->schedules->addDependency($this->schedule($project), $request->validated(), $request->user());

        return $this->respond($request, $project, null, 201);
    }

    public function destroyDependency(Request $request, string $uuid, int $id): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        $schedule = $this->schedule($project);
        $this->schedules->deleteDependency($schedule, ScheduleDependency::query()->where('schedule_id', $schedule->id)->findOrFail($id), $request->user());

        return $this->respond($request, $project);
    }

    public function storeBaseline(ScheduleRequest $request, string $uuid): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        $baseline = $this->schedules->createBaseline($this->schedule($project), $request->validated('name'), $request->user());

        return $this->respond($request, $project, $baseline->number, 201);
    }

    // ------------------------------------------------------------ importação por planilha

    // ------------------------------------------------------------ 6B — custos

    /** BDI (informado ou TCU) e desconto do planejamento (D-P92). */
    public function pricing(ScheduleRequest $request, string $uuid): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        $this->items->setPricing($this->schedule($project), $request->validated(), $request->user());

        return $this->respond($request, $project);
    }

    public function storeItems(ScheduleRequest $request, string $uuid, int $id): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        // D-P97: buscar no catálogo (e composições próprias) exige resources.view; no SINAPI, references.view
        $needs = ['catalog' => 'resources.view', 'own_composition' => 'resources.view', 'sinapi_composition' => 'references.view', 'sinapi_resource' => 'references.view'];
        $perm = $needs[$request->validated('source')] ?? null;
        if ($perm !== null && ! $request->user()->can($perm)) {
            throw ApiException::forbidden('forbidden', 'Seu acesso não permite lançar recursos desta origem.');
        }
        $schedule = $this->schedule($project);
        $this->items->add($schedule, $this->schedules->task($schedule, $id), $request->validated(), $request->user());

        return $this->respond($request, $project, null, 201);
    }

    public function updateItem(ScheduleRequest $request, string $uuid, int $id): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        $item = ScheduleTaskItem::query()->where('schedule_id', $this->schedule($project)->id)->findOrFail($id);
        $this->items->update($item, $request->validated(), $request->user());

        return $this->respond($request, $project);
    }

    public function destroyItem(Request $request, string $uuid, int $id): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        $this->items->delete(ScheduleTaskItem::query()->where('schedule_id', $this->schedule($project)->id)->findOrFail($id), $request->user());

        return $this->respond($request, $project);
    }

    /** D-P93: "Trazer custos do orçamento" — só nas atividades vinculadas a itens e ainda sem recursos. */
    public function itemsFromBudget(Request $request, string $uuid): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        $schedule = $this->schedule($project);
        if ($schedule->budget_version_id === null) {
            throw new DomainRuleException('Este planejamento não foi criado de um orçamento.');
        }
        $this->items->fromBudget($schedule, $request->user());

        return $this->respond($request, $project);
    }

    public function importTemplate(Request $request, string $uuid, ScheduleSheetImporter $importer): BinaryFileResponse
    {
        $this->project($request, $uuid, 'view');
        $path = tempnam(sys_get_temp_dir(), 'plan').'.xlsx';
        $importer->template($path);

        return response()->download($path, 'modelo-planejamento-obraflow.xlsx')->deleteFileAfterSend();
    }

    public function importPreview(ScheduleRequest $request, string $uuid, ScheduleSheetImporter $importer): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');

        return response()->json(['data' => $importer->preview($request->file('file')->getRealPath(), $project)]);
    }

    public function import(ScheduleRequest $request, string $uuid, ScheduleSheetImporter $importer): JsonResponse
    {
        $project = $this->project($request, $uuid, 'edit');
        $file = $request->file('file');
        $summary = $importer->import($file->getRealPath(), $file->getClientOriginalName(), $project, $request->user());

        return response()->json(['data' => ['import' => $summary]], 201);
    }

    // ------------------------------------------------------------ apoio

    /** Compara códigos de EAP parte a parte (1.1.1 antes de 1.2; o <=> de arrays do PHP compara o tamanho primeiro). */
    private static function wbsCompare(string $a, string $b): int
    {
        $x = array_map('intval', explode('.', $a));
        $y = array_map('intval', explode('.', $b));
        for ($i = 0, $n = min(count($x), count($y)); $i < $n; $i++) {
            if ($x[$i] !== $y[$i]) {
                return $x[$i] <=> $y[$i];
            }
        }

        return count($x) <=> count($y);
    }

    private function project(Request $request, string $uuid, string $ability): Project
    {
        $project = Project::query()->where('uuid', $uuid)->firstOrFail();
        $level = $this->access->level($request->user(), $project);
        if ($level === null) {
            throw ApiException::notFound();
        }
        if (ProjectAccess::RANK[$level] < ProjectAccess::RANK[$ability] || ($ability === 'edit' && ! $request->user()->can('schedules.manage'))) {
            throw ApiException::forbidden('forbidden', 'Seu acesso não permite alterar o planejamento desta obra.');
        }

        return $project;
    }

    private function schedule(Project $project): Schedule
    {
        return Schedule::query()->where('project_id', $project->id)->first()
            ?? throw ApiException::notFound('A obra ainda não tem planejamento.');
    }

    private function respond(Request $request, Project $project, ?int $baselineNumber = null, int $status = 200): JsonResponse
    {
        $canEdit = $request->user()->can('schedules.manage') && $this->access->allows($request->user(), $project, 'edit');
        $schedule = Schedule::query()->where('project_id', $project->id)->with('budgetVersion.budget')->first();
        if ($schedule === null) {
            return response()->json(['data' => ['schedule' => null, 'abilities' => ['edit' => $canEdit]]], $status);
        }

        // recalcula na consulta também: uma exceção nova no calendário (feriado, parada) move as datas na hora
        $info = DB::transaction(fn () => $this->scheduler->recompute($schedule));
        // ordem da árvore (1, 1.1, 1.1.1, 1.2…): a tela desenha a EAP na ordem recebida
        $tasks = ScheduleTask::query()->where('schedule_id', $schedule->id)->get()
            ->sort(fn ($a, $b) => self::wbsCompare($a->wbs_code, $b->wbs_code))->values();
        $deps = ScheduleDependency::query()->where('schedule_id', $schedule->id)->orderBy('id')->get();
        $baselines = ScheduleBaseline::query()->where('schedule_id', $schedule->id)->with('creator:id,uuid,name')->orderBy('number')->get();
        $compare = $baselineNumber ? $baselines->firstWhere('number', $baselineNumber)?->snapshot : null;
        $today = CarbonImmutable::today();
        $predsOf = $deps->groupBy('successor_id');
        $costs = $this->costs->compute($schedule, $tasks); // 6B: custo e venda (só leitura)
        $items = ScheduleTaskItem::query()->where('schedule_id', $schedule->id)->orderBy('sort_order')->orderBy('id')->get();

        $rows = $tasks->map(function (ScheduleTask $t) use ($info, $compare, $today, $predsOf, $costs) {
            $base = $compare[$t->id] ?? null;
            $late = $t->kind !== 'summary' && $t->planned_end->lt($today) && (float) $t->percent_complete < 100;

            return [
                'id' => $t->id, 'parent_id' => $t->parent_id, 'wbs' => $t->wbs_code, 'name' => $t->name, 'kind' => $t->kind,
                'unit_code' => $t->unit_code, 'quantity' => $t->quantity !== null ? (string) $t->quantity : null,
                'start' => $t->planned_start->toDateString(), 'end' => $t->planned_end->toDateString(),
                'duration_days' => $t->duration_days, 'calendar_days' => $t->kind === 'milestone' ? 0 : (int) $t->planned_start->diffInDays($t->planned_end) + 1,
                'actual_start' => $t->actual_start?->toDateString(), 'actual_end' => $t->actual_end?->toDateString(),
                'percent' => (string) $t->percent_complete, 'from_budget' => $t->budget_item_id !== null,
                'critical' => $info[$t->id]['critical'] ?? false, 'float' => $info[$t->id]['float'] ?? null, 'late' => $late,
                'predecessors' => $predsOf->get($t->id, collect())->map(fn ($d) => ['id' => $d->id, 'task_id' => $d->predecessor_id, 'type' => $d->type, 'lag' => $d->lag_days])->values(),
                // comparação com a linha de base (D-P71): desvio do término em dias corridos (+ = atrasou)
                'baseline' => $base ? ['start' => $base['start'], 'end' => $base['end'],
                    'delta_days' => (int) CarbonImmutable::parse($base['end'])->diffInDays($t->planned_end, false)] : null,
                'cost' => $costs['tasks'][$t->id] ?? null,
            ];
        });
        $roots = $tasks->whereNull('parent_id');
        $leaves = $tasks->where('kind', '!=', 'summary');
        $weight = $leaves->sum(fn ($t) => $t->kind === 'milestone' ? 0 : max(1, $t->duration_days));

        return response()->json(['data' => [
            'schedule' => ['uuid' => $schedule->uuid, 'origin' => $schedule->origin, 'duration_mode' => $schedule->duration_mode,
                'bdi_percent' => (string) $schedule->bdi_percent, 'bdi_components' => $schedule->bdi_components, 'discount_percent' => (string) $schedule->discount_percent,
                'regime' => $project->social_charges_regime, 'created_at' => $schedule->created_at?->toIso8601String(),
                'budget' => $schedule->budgetVersion ? ['uuid' => $schedule->budgetVersion->budget?->uuid, 'code' => $schedule->budgetVersion->budget?->code,
                    'version' => $schedule->budgetVersion->version_number] : null],
            'summary' => [
                'start' => $roots->isEmpty() ? null : $tasks->min(fn ($t) => $t->planned_start->toDateString()),
                'end' => $roots->isEmpty() ? null : $tasks->max(fn ($t) => $t->planned_end->toDateString()),
                'percent' => $weight > 0 ? number_format($leaves->sum(fn ($t) => ($t->kind === 'milestone' ? 0 : max(1, $t->duration_days)) * (float) $t->percent_complete) / $weight, 2, '.', '') : '0.00',
                'tasks' => $leaves->count(), 'critical' => collect($info)->where('critical', true)->count(), 'late' => $rows->where('late', true)->count(),
            ],
            'tasks' => $rows->values(),
            'dependencies' => $deps->map(fn ($d) => ['id' => $d->id, 'predecessor_id' => $d->predecessor_id, 'successor_id' => $d->successor_id, 'type' => $d->type, 'lag_days' => $d->lag_days])->values(),
            'baselines' => $baselines->map(fn ($b) => ['number' => $b->number, 'name' => $b->name, 'created_at' => $b->created_at?->toIso8601String(), 'created_by' => $b->creator?->only(['uuid', 'name'])])->values(),
            'compared_baseline' => $compare ? $baselineNumber : null,
            // 6B — recursos das atividades e totais do orçamento sintético
            'items' => $items->map(fn (ScheduleTaskItem $i) => [
                'id' => $i->id, 'task_id' => $i->task_id, 'source' => $i->source, 'kind' => $i->kind, 'code' => $i->code, 'description' => $i->description,
                'unit_code' => $i->unit_code, 'quantity_per_unit' => (string) $i->quantity_per_unit, 'unit_cost' => (string) $i->unit_cost,
                'costs' => array_combine(ScheduleTaskItem::COST_KINDS, array_map(fn ($k) => (string) $i->{"cost_{$k}"}, ScheduleTaskItem::COST_KINDS)),
                'bdi_percent' => $i->bdi_percent !== null ? (string) $i->bdi_percent : null, 'discount_percent' => $i->discount_percent !== null ? (string) $i->discount_percent : null,
                'source_ref' => $i->source_ref, 'breakdown' => $i->breakdown,
                'computed' => array_diff_key($costs['items'][$i->id] ?? [], ['_unit' => 1, '_factor' => 1]),
            ])->values(),
            'costs' => $costs['totals'],
            'abilities' => ['edit' => $canEdit],
        ]], $status);
    }
}
