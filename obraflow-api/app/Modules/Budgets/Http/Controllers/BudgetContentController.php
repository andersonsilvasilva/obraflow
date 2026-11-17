<?php

namespace App\Modules\Budgets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetBdi;
use App\Models\BudgetComposition;
use App\Models\BudgetGroup;
use App\Models\BudgetIndirectCost;
use App\Models\BudgetItem;
use App\Models\BudgetItemResource;
use App\Models\BudgetVersion;
use App\Modules\Budgets\Http\Requests\BudgetContentRequest;
use App\Modules\Budgets\Services\AbcCurve;
use App\Modules\Budgets\Services\BudgetAccess;
use App\Modules\Budgets\Services\BudgetService;
use App\Modules\Shared\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conteúdo de uma versão (endereçada por orçamento + número): leitura completa, etapas, itens, BDI, custos indiretos,
 * composição do item e Curva ABC. Escritas só em rascunho e nunca em versão bloqueada (o serviço confere — D-P31).
 */
class BudgetContentController extends Controller
{
    public function __construct(private readonly BudgetService $budgets, private readonly BudgetAccess $access, private readonly AbcCurve $abc) {}

    /** Versão completa: totais, etapas (árvore), itens, BDI e indiretos. */
    public function show(Request $request, string $uuid, int $number): JsonResponse
    {
        $v = $this->version($request, $uuid, $number, 'view');

        return response()->json(['data' => [
            'number' => $v->version_number, 'label' => $v->label, 'status' => $v->status, 'locked' => $v->isLocked(),
            'social_charges_regime' => $v->social_charges_regime, 'discount_percent' => $v->discount_percent,
            'approved_at' => $v->approved_at?->toIso8601String(), 'totals' => $v->totals_snapshot,
            'groups' => BudgetGroup::query()->where('budget_version_id', $v->id)->orderBy('sort_order')->orderBy('id')->get(['id', 'parent_id', 'code', 'name', 'sort_order']),
            'items' => BudgetItem::query()->where('budget_version_id', $v->id)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (BudgetItem $i) => $i->only(['id', 'group_id', 'code', 'description', 'unit_code', 'quantity', 'unit_cost', 'bdi_percent',
                    'discount_percent', 'total_cost', 'total_sale', 'source', 'source_ref', 'sort_order'])),
            'bdi' => BudgetBdi::query()->where('budget_version_id', $v->id)->get(['id', 'scope', 'group_id', 'item_id', 'method', 'components', 'percent']),
            'indirect_costs' => BudgetIndirectCost::query()->where('budget_version_id', $v->id)->orderBy('sort_order')->get(['id', 'description', 'method', 'percent', 'amount']),
        ]]);
    }

    // ------------------------------------------------------------ etapas

    public function storeGroup(BudgetContentRequest $request, string $uuid, int $number): JsonResponse
    {
        $g = $this->budgets->addGroup($this->version($request, $uuid, $number, 'edit'), $request->validated(), $request->user());

        return response()->json(['data' => $g->only(['id', 'parent_id', 'code', 'name', 'sort_order'])], 201);
    }

    public function updateGroup(BudgetContentRequest $request, string $uuid, int $number, int $id): JsonResponse
    {
        $v = $this->version($request, $uuid, $number, 'edit');
        $g = $this->budgets->updateGroup(BudgetGroup::query()->where('budget_version_id', $v->id)->findOrFail($id), $request->validated(), $request->user());

        return response()->json(['data' => $g->only(['id', 'parent_id', 'code', 'name', 'sort_order'])]);
    }

    public function destroyGroup(Request $request, string $uuid, int $number, int $id): JsonResponse
    {
        $v = $this->version($request, $uuid, $number, 'edit');
        $this->budgets->deleteGroup(BudgetGroup::query()->where('budget_version_id', $v->id)->findOrFail($id), $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ itens

    public function storeItem(BudgetContentRequest $request, string $uuid, int $number): JsonResponse
    {
        $item = $this->budgets->addItem($this->version($request, $uuid, $number, 'edit'), $request->validated() + ['source' => 'manual'], $request->user());

        return response()->json(['data' => $this->itemData($item)], 201);
    }

    public function storeReferenceItem(BudgetContentRequest $request, string $uuid, int $number): JsonResponse
    {
        $item = $this->budgets->addReferenceItem($this->version($request, $uuid, $number, 'edit'), $request->validated(), $request->user());

        return response()->json(['data' => $this->itemData($item)], 201);
    }

    public function storeCatalogItem(BudgetContentRequest $request, string $uuid, int $number): JsonResponse
    {
        $item = $this->budgets->addCatalogItem($this->version($request, $uuid, $number, 'edit'), $request->validated(), $request->user());

        return response()->json(['data' => $this->itemData($item)], 201);
    }

    public function updateItem(BudgetContentRequest $request, string $uuid, int $number, int $id): JsonResponse
    {
        $v = $this->version($request, $uuid, $number, 'edit');
        $item = $this->budgets->updateItem(BudgetItem::query()->where('budget_version_id', $v->id)->findOrFail($id), $request->validated(), $request->user());

        return response()->json(['data' => $this->itemData($item)]);
    }

    public function destroyItem(Request $request, string $uuid, int $number, int $id): JsonResponse
    {
        $v = $this->version($request, $uuid, $number, 'edit');
        $this->budgets->deleteItem(BudgetItem::query()->where('budget_version_id', $v->id)->findOrFail($id), $request->user());

        return response()->json(null, 204);
    }

    /** Composição copiada do item (itens diretos) e insumos finais. */
    public function composition(Request $request, string $uuid, int $number, int $id): JsonResponse
    {
        $v = $this->version($request, $uuid, $number, 'view');
        $item = BudgetItem::query()->where('budget_version_id', $v->id)->findOrFail($id);
        $comp = BudgetComposition::query()->where('budget_item_id', $item->id)->with('items')->first();

        return response()->json(['data' => [
            'item' => $this->itemData($item),
            'composition' => $comp ? $comp->only(['code', 'description', 'unit_code', 'unit_cost', 'source_snapshot']) + [
                'items' => $comp->items->map(fn ($ci) => $ci->only(['item_type', 'kind', 'code', 'description', 'unit_code', 'coefficient', 'unit_price', 'cost', 'price_origin'])),
            ] : null,
            'resources' => BudgetItemResource::query()->where('budget_item_id', $item->id)->orderBy('code')
                ->get(['kind', 'code', 'description', 'unit_code', 'coefficient', 'unit_price']),
        ]]);
    }

    // ------------------------------------------------------------ BDI e indiretos

    /** Define, troca (informado ou TCU) ou remove (`remove: true`) o BDI de um alvo. */
    public function bdi(BudgetContentRequest $request, string $uuid, int $number): JsonResponse
    {
        $v = $this->version($request, $uuid, $number, 'edit');
        $d = $request->validated();
        $target = $d['scope'] === 'group' ? (int) $d['group_id'] : ($d['scope'] === 'item' ? (int) $d['item_id'] : null);
        $remove = (bool) ($d['remove'] ?? false);
        $bdi = $this->budgets->setBdi($v, $d['scope'], $target, $d['method'],
            $remove ? null : (isset($d['percent']) ? (string) $d['percent'] : null),
            $remove ? null : ($d['components'] ?? null), $request->user());

        return response()->json(['data' => $bdi?->only(['id', 'scope', 'group_id', 'item_id', 'method', 'components', 'percent']), 'totals' => $v->fresh()->totals_snapshot]);
    }

    public function storeIndirect(BudgetContentRequest $request, string $uuid, int $number): JsonResponse
    {
        $c = $this->budgets->addIndirect($this->version($request, $uuid, $number, 'edit'), $request->validated(), $request->user());

        return response()->json(['data' => $c->only(['id', 'description', 'method', 'percent', 'amount'])], 201);
    }

    public function updateIndirect(BudgetContentRequest $request, string $uuid, int $number, int $id): JsonResponse
    {
        $v = $this->version($request, $uuid, $number, 'edit');
        $c = $this->budgets->updateIndirect(BudgetIndirectCost::query()->where('budget_version_id', $v->id)->findOrFail($id), $request->validated(), $request->user());

        return response()->json(['data' => $c->only(['id', 'description', 'method', 'percent', 'amount'])]);
    }

    public function destroyIndirect(Request $request, string $uuid, int $number, int $id): JsonResponse
    {
        $v = $this->version($request, $uuid, $number, 'edit');
        $this->budgets->deleteIndirect(BudgetIndirectCost::query()->where('budget_version_id', $v->id)->findOrFail($id), $request->user());

        return response()->json(null, 204);
    }

    /** Curva ABC (`type=services` padrão, ou `resources`). */
    public function abc(Request $request, string $uuid, int $number): JsonResponse
    {
        $v = $this->version($request, $uuid, $number, 'view');

        return response()->json(['data' => $request->query('type') === 'resources' ? $this->abc->resources($v) : $this->abc->services($v)]);
    }

    // ------------------------------------------------------------ apoio

    private function version(Request $request, string $uuid, int $number, string $ability): BudgetVersion
    {
        $budget = Budget::query()->where('uuid', $uuid)->firstOrFail();
        if (! $this->access->allows($request->user(), $budget, 'view')) {
            throw ApiException::notFound();
        }
        if ($ability !== 'view' && ! $this->access->allows($request->user(), $budget, $ability)) {
            throw ApiException::forbidden('forbidden', 'Você não tem permissão para alterar este orçamento.');
        }

        return BudgetVersion::query()->where('budget_id', $budget->id)->where('version_number', $number)->firstOrFail()->setRelation('budget', $budget);
    }

    private function itemData(BudgetItem $item): array
    {
        return $item->only(['id', 'group_id', 'code', 'description', 'unit_code', 'quantity', 'unit_cost', 'bdi_percent', 'discount_percent',
            'total_cost', 'total_sale', 'source', 'source_ref', 'sort_order']);
    }
}
