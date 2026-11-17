<?php

namespace App\Modules\Budgets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetApproval;
use App\Models\BudgetStatusHistory;
use App\Models\BudgetVersion;
use App\Models\Company;
use App\Models\Project;
use App\Modules\Budgets\Http\Requests\BudgetContentRequest;
use App\Modules\Budgets\Http\Requests\BudgetRequest;
use App\Modules\Budgets\Http\Resources\BudgetResource;
use App\Modules\Budgets\Services\BudgetAccess;
use App\Modules\Budgets\Services\BudgetService;
use App\Modules\Budgets\Services\BudgetWorkflow;
use App\Modules\Projects\Services\ProjectAccess;
use App\Modules\Registry\Http\Controllers\Concerns\ListsRecords;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Shared\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Orçamentos, versões e fluxo de aprovação (D-P34). Autorização em duas camadas: permissão na rota e
 * BudgetAccess por orçamento (acesso por obra quando houver). Orçamento invisível → 404.
 */
class BudgetController extends Controller
{
    use ListsRecords;

    private const RELATIONS = ['company', 'project', 'customer', 'dataset', 'owner', 'currentVersion'];

    public function __construct(
        private readonly BudgetService $budgets,
        private readonly BudgetWorkflow $workflow,
        private readonly BudgetAccess $access,
        private readonly RegistryService $registry,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->access->visible(Budget::query()->with(self::RELATIONS), $request->user())->orderByDesc('id');
        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $like = '%'.addcslashes(mb_substr($term, 0, 100), '%_\\').'%';
            $query->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('title', 'like', $like));
        }
        foreach (['status', 'mode'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        foreach (['company_uuid' => [Company::class, 'company_id'], 'project_uuid' => [Project::class, 'project_id']] as $param => [$model, $column]) {
            if ($request->filled($param)) {
                $query->where($column, $model::query()->where('uuid', $request->query($param))->value('id') ?? 0);
            }
        }

        return $this->paginated($query, $request, BudgetResource::class);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $budget = $this->find($request, $uuid, 'view')->load([...self::RELATIONS, 'versions']);

        return $this->respond($request, $budget);
    }

    public function store(BudgetRequest $request): JsonResponse
    {
        $data = $request->payload();
        if (! empty($data['project_id'])) {
            $project = Project::query()->findOrFail($data['project_id']);
            if (! app(ProjectAccess::class)->allows($request->user(), $project, 'edit')) {
                throw ApiException::forbidden('forbidden', 'Seu nível de acesso na obra não permite criar orçamentos nela.');
            }
        }
        $budget = $this->budgets->create($data, $request->user());

        return $this->respond($request, $budget->load([...self::RELATIONS, 'versions']), 201);
    }

    public function update(BudgetRequest $request, string $uuid): JsonResponse
    {
        $budget = $this->budgets->update($this->find($request, $uuid, 'edit'), $request->payload(), $request->user());

        return $this->respond($request, $budget->load([...self::RELATIONS, 'versions']));
    }

    /** Soft delete; orçamento com versão aprovada não é excluído (preserva o histórico de aprovação). */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $budget = $this->find($request, $uuid, 'edit');
        if (BudgetVersion::query()->where('budget_id', $budget->id)->whereNotNull('locked_at')->exists()) {
            throw ApiException::conflict('budget_approved', 'Orçamento com versão aprovada não pode ser excluído.');
        }
        $this->registry->delete($budget, $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ versões

    public function newVersion(BudgetContentRequest $request, string $uuid): JsonResponse
    {
        $budget = $this->find($request, $uuid, 'edit');
        $version = $this->budgets->newVersion($budget, $request->user(), $request->validated('label'));

        return response()->json(['data' => ['number' => $version->version_number, 'status' => $version->status]], 201);
    }

    public function updateVersion(BudgetContentRequest $request, string $uuid, int $number): JsonResponse
    {
        $budget = $this->find($request, $uuid, 'edit');
        $totals = $this->budgets->updateVersion($this->version($budget, $number), $request->validated(), $request->user());

        return response()->json(['data' => ['totals' => $totals]]);
    }

    /** Ações do fluxo: submit, withdraw, approve, reject, reopen (sempre sobre a versão corrente). */
    public function workflow(BudgetContentRequest $request, string $uuid, string $action): JsonResponse
    {
        $ability = in_array($action, ['approve', 'reject'], true) ? 'approve' : 'edit';
        $budget = $this->find($request, $uuid, $ability);
        $version = $this->workflow->run($budget, $action, $request->user(), $request->validated('comment'));

        return response()->json(['data' => ['number' => $version->version_number, 'status' => $version->status, 'locked' => $version->isLocked()]]);
    }

    public function history(Request $request, string $uuid): JsonResponse
    {
        $budget = $this->find($request, $uuid, 'view');
        $versions = BudgetVersion::query()->where('budget_id', $budget->id)->pluck('version_number', 'id');

        return response()->json(['data' => [
            'status' => BudgetStatusHistory::query()->where('budget_id', $budget->id)->with('changer:id,uuid,name')->orderByDesc('changed_at')->orderByDesc('id')->get()
                ->map(fn ($h) => ['version' => $versions[$h->budget_version_id] ?? null, 'from' => $h->from_status, 'to' => $h->to_status,
                    'reason' => $h->reason, 'at' => $h->changed_at?->toIso8601String(), 'by' => $h->changer?->only(['uuid', 'name'])]),
            'decisions' => BudgetApproval::query()->whereIn('budget_version_id', $versions->keys())->with('decider:id,uuid,name')->orderByDesc('decided_at')->get()
                ->map(fn ($a) => ['version' => $versions[$a->budget_version_id], 'decision' => $a->decision, 'comment' => $a->comment,
                    'totals_hash' => $a->totals_hash, 'at' => $a->decided_at?->toIso8601String(), 'by' => $a->decider?->only(['uuid', 'name'])]),
        ]]);
    }

    // ------------------------------------------------------------ apoio

    private function find(Request $request, string $uuid, string $ability): Budget
    {
        $budget = Budget::query()->where('uuid', $uuid)->firstOrFail();
        if (! $this->access->allows($request->user(), $budget, 'view')) {
            throw ApiException::notFound();
        }
        if ($ability !== 'view' && ! $this->access->allows($request->user(), $budget, $ability)) {
            throw ApiException::forbidden('forbidden', 'Você não tem permissão para esta ação neste orçamento.');
        }

        return $budget;
    }

    private function version(Budget $budget, int $number): BudgetVersion
    {
        return BudgetVersion::query()->where('budget_id', $budget->id)->where('version_number', $number)->firstOrFail();
    }

    private function respond(Request $request, Budget $budget, int $status = 200): JsonResponse
    {
        $user = $request->user();
        $resource = (new BudgetResource($budget))->withAbilities([
            'edit' => $this->access->allows($user, $budget, 'edit'),
            'approve' => $this->access->allows($user, $budget, 'approve'),
        ]);

        return response()->json(['data' => $resource], $status);
    }
}
