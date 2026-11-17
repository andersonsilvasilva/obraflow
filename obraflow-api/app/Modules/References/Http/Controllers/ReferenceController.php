<?php

namespace App\Modules\References\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ReferenceComposition;
use App\Models\ReferenceDataset;
use App\Models\ReferenceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consulta das referências técnicas (D-P29): dados GLOBAIS e somente leitura. Só bases `active` ou `superseded`
 * são visíveis (importação em curso ou com falha → 404). A rota exige tenant ativo e `references.view`.
 */
class ReferenceController extends Controller
{
    public function datasets(Request $request): JsonResponse
    {
        $query = ReferenceDataset::query()->with('source:id,code,name,publisher')
            ->whereIn('status', $request->boolean('include_superseded') ? ['active', 'superseded'] : ['active'])
            ->withCount(['resources', 'compositions'])
            ->orderByDesc('version')->orderBy('state');
        foreach (['state', 'charges_regime'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        return response()->json(['data' => $query->get()->map(fn (ReferenceDataset $d) => $this->datasetData($d))]);
    }

    public function resources(Request $request, int $dataset): JsonResponse
    {
        $ds = $this->dataset($dataset);
        $query = ReferenceResource::query()->where('dataset_id', $ds->id)->with(['unit:id,code', 'price'])->orderBy('description');
        $this->search($query, $request);
        if ($request->filled('kind')) {
            $request->query('kind') === 'none' ? $query->whereNull('kind') : $query->where('kind', $request->query('kind'));
        }

        return $this->page($query, $request, fn (ReferenceResource $r) => $this->resourceData($r));
    }

    public function resource(int $dataset, string $code): JsonResponse
    {
        $ds = $this->dataset($dataset);
        $r = ReferenceResource::query()->where('dataset_id', $ds->id)->where('code', $code)->with(['unit:id,code', 'price'])->firstOrFail();

        return response()->json(['data' => $this->resourceData($r) + ['dataset' => $this->datasetData($ds->load('source'))]]);
    }

    public function compositions(Request $request, int $dataset): JsonResponse
    {
        $ds = $this->dataset($dataset);
        $query = ReferenceComposition::query()->where('dataset_id', $ds->id)->with('unit:id,code')->orderBy('description');
        $this->search($query, $request);
        if ($request->filled('class_code')) {
            $query->where('class_code', $request->query('class_code'));
        }

        return $this->page($query, $request, fn (ReferenceComposition $c) => $this->compositionData($c));
    }

    /** Detalhe: itens com insumo OU composição filha, coeficiente (7 casas) e preço/custo publicados. */
    public function composition(int $dataset, string $code): JsonResponse
    {
        $ds = $this->dataset($dataset);
        $c = ReferenceComposition::query()->where('dataset_id', $ds->id)->where('code', $code)
            ->with(['unit:id,code', 'items.resource.unit:id,code', 'items.resource.price', 'items.child.unit:id,code'])->firstOrFail();

        return response()->json(['data' => $this->compositionData($c) + [
            'costs' => [
                'labor' => $c->labor_cost, 'material' => $c->material_cost, 'equipment' => $c->equipment_cost,
                'services' => $c->services_cost, 'other' => $c->other_cost,
            ],
            'type' => ['code' => $c->type_code, 'name' => $c->type_name],
            'group' => ['code' => $c->group_code, 'name' => $c->group_name],
            'items' => $c->items->map(fn ($i) => [
                'type' => $i->resource_id ? 'resource' : 'composition',
                'code' => $i->resource?->code ?? $i->child?->code,
                'description' => $i->resource?->description ?? $i->child?->description,
                'unit' => $i->resource?->unit?->code ?? $i->child?->unit?->code,
                'kind' => $i->resource?->kind,
                'coefficient' => $i->coefficient,
                'unit_price' => $i->unit_price,
                'cost' => $i->cost,
                'price_origin' => $i->price_origin,
            ]),
            'dataset' => $this->datasetData($ds->load('source')),
        ]]);
    }

    // ------------------------------------------------------------ apoio

    private function dataset(int $id): ReferenceDataset
    {
        return ReferenceDataset::query()->whereKey($id)->whereIn('status', ['active', 'superseded'])->firstOrFail();
    }

    /** Busca: só dígitos → código (prefixo); senão, descrição contendo o termo (curingas escapados). */
    private function search(Builder $query, Request $request): void
    {
        $term = trim((string) $request->query('q', ''));
        if ($term === '') {
            return;
        }
        if (ctype_digit($term)) {
            $query->where('code', 'like', $term.'%');

            return;
        }
        foreach (preg_split('/\s+/', mb_substr($term, 0, 100)) as $word) {
            $query->where('description', 'like', '%'.addcslashes($word, '%_\\').'%');
        }
    }

    private function page(Builder $query, Request $request, callable $map): JsonResponse
    {
        $page = $query->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => $page->getCollection()->map($map)->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    private function datasetData(ReferenceDataset $d): array
    {
        return [
            'id' => $d->id,
            'source' => $d->source?->only(['code', 'name', 'publisher']),
            'competency' => $d->version,
            'state' => $d->state,
            'location' => $d->location_name,
            'charges_regime' => $d->charges_regime,
            'status' => $d->status,
            'activated_at' => $d->activated_at?->toIso8601String(),
            'resources_count' => $d->resources_count ?? null,
            'compositions_count' => $d->compositions_count ?? null,
        ];
    }

    private function resourceData(ReferenceResource $r): array
    {
        return [
            'code' => $r->code, 'description' => $r->description, 'unit' => $r->unit?->code, 'kind' => $r->kind,
            'family_class' => $r->family_class, 'price' => $r->price?->price, 'price_origin' => $r->price?->price_origin,
        ];
    }

    private function compositionData(ReferenceComposition $c): array
    {
        return [
            'code' => $c->code, 'description' => $c->description, 'unit' => $c->unit?->code, 'total_cost' => $c->total_cost,
            'price_origin' => $c->price_origin, 'class' => ['code' => $c->class_code, 'name' => $c->class_name],
        ];
    }
}
