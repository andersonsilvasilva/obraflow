<?php

namespace App\Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetVersion;
use App\Models\Contract;
use App\Models\ContractAddendum;
use App\Models\ContractAddendumItem;
use App\Models\ContractItem;
use App\Models\ContractStatusHistory;
use App\Models\DocumentLink;
use App\Models\Project;
use App\Modules\Budgets\Services\BudgetAccess;
use App\Modules\Contracts\Http\Requests\ContractContentRequest;
use App\Modules\Contracts\Http\Requests\ContractRequest;
use App\Modules\Contracts\Http\Resources\ContractResource;
use App\Modules\Contracts\Services\ContractAccess;
use App\Modules\Contracts\Services\ContractAddendumService;
use App\Modules\Contracts\Services\ContractService;
use App\Modules\Contracts\Services\ContractWorkflow;
use App\Modules\Projects\Services\ProjectAccess;
use App\Modules\Registry\Http\Controllers\Concerns\ListsRecords;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Shared\Support\YearlyCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contratos da obra (Fase 5A). Autorização em duas camadas: permissão na rota e ContractAccess por contrato (acesso
 * por obra — A-45/D-P56). Contrato invisível → 404. Aditivos endereçados pelo número dentro do contrato.
 */
class ContractController extends Controller
{
    use ListsRecords;

    private const RELATIONS = ['company', 'project', 'customer', 'supplier', 'budgetVersion.budget', 'creator'];

    public function __construct(
        private readonly ContractService $contracts,
        private readonly ContractWorkflow $workflow,
        private readonly ContractAddendumService $addenda,
        private readonly ContractAccess $access,
    ) {}

    public function nextCode(Request $request, YearlyCodeGenerator $codes): JsonResponse
    {
        $year = YearlyCodeGenerator::yearOf($request->filled('starts_at') ? (string) $request->query('starts_at') : null);

        return response()->json(['data' => ['code' => $codes->suggest(Contract::class, 'CTR', $year), 'year' => $year]]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->access->visible(Contract::query()->with(self::RELATIONS)->withCount('items')
            ->selectSub(DocumentLink::query()->selectRaw('count(*)')->where('linkable_type', 'contract')->whereColumn('linkable_id', 'contracts.id'), 'documents_count')
            ->addSelect('contracts.*'), $request->user())->orderByDesc('id');
        $this->search($query, $request, ['code', 'object'], digitsColumn: null);
        foreach (['status', 'kind'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('project_uuid')) {
            $query->where('project_id', Project::query()->where('uuid', $request->query('project_uuid'))->value('id') ?? 0);
        }

        return $this->paginated($query, $request, ContractResource::class);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return $this->respond($request, $this->find($request, $uuid, 'view'));
    }

    public function store(ContractRequest $request): JsonResponse
    {
        $data = $request->payload();
        $project = Project::query()->findOrFail($data['project_id']);
        if (! $request->user()->can('contracts.manage') || ! app(ProjectAccess::class)->allows($request->user(), $project, 'edit')) {
            throw ApiException::forbidden('forbidden', 'Seu nível de acesso na obra não permite criar contratos nela.');
        }

        return $this->respond($request, $this->contracts->create($data, $request->user()), 201);
    }

    public function update(ContractRequest $request, string $uuid): JsonResponse
    {
        $contract = $this->contracts->update($this->find($request, $uuid, 'edit'), $request->payload(), $request->user());

        return $this->respond($request, $contract);
    }

    /** Ações do ciclo de vida: activate, suspend, resume, complete, cancel. */
    public function workflow(ContractContentRequest $request, string $uuid, string $action): JsonResponse
    {
        $contract = $this->workflow->run($this->find($request, $uuid, 'edit'), $action, $request->user(),
            $request->validated('reason'), $request->validated('signed_at'));

        return $this->respond($request, $contract);
    }

    public function history(Request $request, string $uuid): JsonResponse
    {
        $contract = $this->find($request, $uuid, 'view');

        return response()->json(['data' => ContractStatusHistory::query()->where('contract_id', $contract->id)->with('changer:id,uuid,name')
            ->orderByDesc('changed_at')->orderByDesc('id')->get()
            ->map(fn ($h) => ['from' => $h->from_status, 'to' => $h->to_status, 'reason' => $h->reason,
                'at' => $h->changed_at?->toIso8601String(), 'by' => $h->changer?->only(['uuid', 'name'])])]);
    }

    // ------------------------------------------------------------ itens

    public function items(Request $request, string $uuid): JsonResponse
    {
        $contract = $this->find($request, $uuid, 'view');

        return response()->json(['data' => ContractItem::query()->where('contract_id', $contract->id)->with('addendum:id,number')
            ->orderBy('sort_order')->orderBy('id')->get()->map(fn ($i) => $this->item($i))]);
    }

    public function storeItem(ContractContentRequest $request, string $uuid): JsonResponse
    {
        $item = $this->contracts->addItem($this->find($request, $uuid, 'edit'), $request->validated(), $request->user());

        return response()->json(['data' => $this->item($item)], 201);
    }

    public function updateItem(ContractContentRequest $request, string $uuid, int $id): JsonResponse
    {
        $contract = $this->find($request, $uuid, 'edit');
        $item = $this->contracts->updateItem(ContractItem::query()->where('contract_id', $contract->id)->findOrFail($id), $request->validated(), $request->user());

        return response()->json(['data' => $this->item($item)]);
    }

    public function destroyItem(Request $request, string $uuid, int $id): JsonResponse
    {
        $contract = $this->find($request, $uuid, 'edit');
        $this->contracts->deleteItem(ContractItem::query()->where('contract_id', $contract->id)->findOrFail($id), $request->user());

        return response()->json(null, 204);
    }

    /** Gera os itens da versão aprovada de um orçamento que o usuário pode ver (D-P47). */
    public function fromBudget(ContractContentRequest $request, string $uuid): JsonResponse
    {
        $contract = $this->find($request, $uuid, 'edit');
        $budget = Budget::query()->where('uuid', $request->validated('budget_uuid'))->firstOrFail();
        if (! app(BudgetAccess::class)->allows($request->user(), $budget, 'view')) {
            throw ApiException::notFound();
        }
        $version = BudgetVersion::query()->where('budget_id', $budget->id)->where('version_number', $request->validated('version'))->firstOrFail();

        return response()->json(['data' => $this->contracts->generateFromBudget($contract, $version, $request->user())], 201);
    }

    // ------------------------------------------------------------ aditivos

    public function addenda(Request $request, string $uuid): JsonResponse
    {
        $contract = $this->find($request, $uuid, 'view');
        $rows = ContractAddendum::query()->where('contract_id', $contract->id)->with(['lines.item:id,code,description,unit_code,unit_price,quantity', 'approver:id,uuid,name', 'creator:id,uuid,name'])
            ->orderBy('number')->get();

        return response()->json(['data' => $rows->map(fn ($a) => $this->addendum($a))]);
    }

    public function storeAddendum(ContractContentRequest $request, string $uuid): JsonResponse
    {
        $addendum = $this->addenda->create($this->find($request, $uuid, 'edit'), $request->validated(), $request->user());

        return response()->json(['data' => $this->addendum($addendum->load('lines'))], 201);
    }

    public function updateAddendum(ContractContentRequest $request, string $uuid, int $number): JsonResponse
    {
        $addendum = $this->addenda->update($this->findAddendum($request, $uuid, $number), $request->validated(), $request->user());

        return response()->json(['data' => $this->addendum($addendum->load('lines.item'))]);
    }

    public function approveAddendum(ContractContentRequest $request, string $uuid, int $number): JsonResponse
    {
        $result = $this->addenda->approve($this->findAddendum($request, $uuid, $number), $request->validated('signed_at'), $request->user());

        return response()->json(['data' => array_merge($this->addendum($result['addendum']->load('lines.item')), ['over_25_percent' => $result['over_25_percent']])]);
    }

    public function cancelAddendum(ContractContentRequest $request, string $uuid, int $number): JsonResponse
    {
        $addendum = $this->addenda->cancel($this->findAddendum($request, $uuid, $number), $request->user(), $request->validated('reason'));

        return response()->json(['data' => $this->addendum($addendum->load('lines.item'))]);
    }

    public function storeLine(ContractContentRequest $request, string $uuid, int $number): JsonResponse
    {
        $addendum = $this->findAddendum($request, $uuid, $number);
        $this->addenda->addLine($addendum, $request->validated(), $request->user());

        return response()->json(['data' => $this->addendum($addendum->refresh()->load('lines.item'))], 201);
    }

    public function updateLine(ContractContentRequest $request, string $uuid, int $number, int $id): JsonResponse
    {
        $addendum = $this->findAddendum($request, $uuid, $number);
        $line = ContractAddendumItem::query()->where('addendum_id', $addendum->id)->findOrFail($id);
        $this->addenda->updateLine($line, $addendum, $request->validated(), $request->user());

        return response()->json(['data' => $this->addendum($addendum->refresh()->load('lines.item'))]);
    }

    public function destroyLine(Request $request, string $uuid, int $number, int $id): JsonResponse
    {
        $addendum = $this->findAddendum($request, $uuid, $number);
        $this->addenda->deleteLine(ContractAddendumItem::query()->where('addendum_id', $addendum->id)->findOrFail($id), $addendum, $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ apoio

    private function find(Request $request, string $uuid, string $ability): Contract
    {
        $contract = Contract::query()->where('uuid', $uuid)->firstOrFail();
        if (! $this->access->allows($request->user(), $contract, 'view')) {
            throw ApiException::notFound();
        }
        if ($ability !== 'view' && ! $this->access->allows($request->user(), $contract, $ability)) {
            throw ApiException::forbidden('forbidden', 'Você não tem permissão para esta ação neste contrato.');
        }

        return $contract;
    }

    private function findAddendum(Request $request, string $uuid, int $number): ContractAddendum
    {
        $contract = $this->find($request, $uuid, 'edit');

        return ContractAddendum::query()->where('contract_id', $contract->id)->where('number', $number)->firstOrFail();
    }

    private function respond(Request $request, Contract $contract, int $status = 200): JsonResponse
    {
        $contract->load(self::RELATIONS)->loadCount('items');
        $contract->setAttribute('documents_count', DocumentLink::query()->where('linkable_type', 'contract')->where('linkable_id', $contract->id)->count());
        $resource = (new ContractResource($contract))->withAbilities(['edit' => $this->access->allows($request->user(), $contract, 'edit')]);

        return response()->json(['data' => $resource], $status);
    }

    private function item(ContractItem $i): array
    {
        return [
            'id' => $i->id, 'code' => $i->code, 'description' => $i->description, 'unit_code' => $i->unit_code,
            'original_quantity' => (string) $i->original_quantity, 'quantity' => (string) $i->quantity,
            'unit_price' => (string) $i->unit_price, 'total' => (string) $i->total, 'sort_order' => $i->sort_order,
            'from_budget' => $i->budget_item_id !== null, 'addendum' => $i->addendum_id ? $i->addendum?->number : null,
        ];
    }

    private function addendum(ContractAddendum $a): array
    {
        $contract = Contract::query()->find($a->contract_id);

        return [
            'number' => $a->number, 'kind' => $a->kind, 'status' => $a->status, 'description' => $a->description,
            'value_delta' => (string) $a->value_delta, 'new_end_date' => $a->new_end_date?->toDateString(), 'signed_at' => $a->signed_at?->toDateString(),
            'approved_at' => $a->approved_at?->toIso8601String(), 'approved_by' => $a->approver?->only(['uuid', 'name']),
            'created_by' => $a->creator?->only(['uuid', 'name']),
            // aviso dos 25% (D-P48): conta os acréscimos aprovados + o deste rascunho
            'over_25_percent' => $a->status === 'draft' && $contract ? $this->addenda->over25($contract, (string) $a->value_delta) : null,
            'lines' => $a->lines->map(fn ($l) => [
                'id' => $l->id, 'line_type' => $l->line_type, 'contract_item_id' => $l->contract_item_id, 'quantity_delta' => (string) $l->quantity_delta,
                'code' => $l->line_type === 'new' ? $l->code : $l->item?->code,
                'description' => $l->line_type === 'new' ? $l->description : $l->item?->description,
                'unit_code' => $l->line_type === 'new' ? $l->unit_code : $l->item?->unit_code,
                'unit_price' => (string) ($l->line_type === 'new' ? $l->unit_price : $l->item?->unit_price),
            ])->values(),
        ];
    }
}
