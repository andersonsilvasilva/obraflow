<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Models\ResourceLaborCost;
use App\Modules\Catalog\Http\Requests\ResourceRequest;
use App\Modules\Catalog\Import\ResourceSheetImporter;
use App\Modules\Catalog\Services\ResourceCatalogService;
use App\Modules\Registry\Http\Controllers\Concerns\ListsRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catálogo de insumos da organização (Fase 4C): CRUD, balanço da mão de obra (D-P39) e importação de planilhas com
 * pré-visualização (D-P43). Escopo do tenant ativo em tudo (outro tenant → 404).
 */
class ResourceController extends Controller
{
    use ListsRecords;

    private const RELATIONS = ['unit:id,code,name', 'group:id,name,parent_id', 'mainSupplier:id,uuid,legal_name,trade_name', 'jobTitle:id,name', 'equipment:id,uuid,name,code'];

    public function __construct(private readonly ResourceCatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $query = Resource::query()->with(self::RELATIONS)->orderBy('name');
        $this->search($query, $request, ['name', 'code', 'brand'], digitsColumn: null);
        foreach (['kind', 'group_id', 'status', 'unit_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('parent_group_id')) { // grupo inteiro: o grupo e seus subgrupos
            $parent = (int) $request->query('parent_group_id');
            $query->where(fn ($q) => $q->where('group_id', $parent)->orWhereIn('group_id', fn ($s) => $s->select('id')->from('resource_groups')->where('parent_id', $parent)));
        }

        $response = $this->paginated($query, $request, ResourceDataResource::class);

        return $response;
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json(['data' => new ResourceDataResource($this->find($uuid)->load([...self::RELATIONS, 'laborCosts']))]);
    }

    public function store(ResourceRequest $request): JsonResponse
    {
        $resource = $this->catalog->create($request->payload(), $request->user());

        return response()->json(['data' => new ResourceDataResource($resource->load(self::RELATIONS))], 201);
    }

    public function update(ResourceRequest $request, string $uuid): JsonResponse
    {
        $resource = $this->catalog->update($this->find($uuid), $request->payload(), $request->user());

        return response()->json(['data' => new ResourceDataResource($resource->load(self::RELATIONS))]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->catalog->delete($this->find($uuid), $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ balanço da mão de obra (D-P39)

    public function laborCosts(string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->find($uuid)->laborCosts()->get()->map(fn ($c) => $this->laborData($c))]);
    }

    public function storeLaborCost(Request $request, string $uuid): JsonResponse
    {
        $resource = $this->find($uuid);
        $data = $request->validate([
            'effective_date' => ['required', 'date', Rule::unique('resource_labor_costs')->where('resource_id', $resource->id)],
            'base_salary' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'extra_hour' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'charges_percent' => ['required', 'numeric', 'min:0', 'max:999.9999', 'decimal:0,4'],
            'admin_percent' => ['required', 'numeric', 'min:0', 'max:999.9999', 'decimal:0,4'],
            'monthly_hours' => ['required', 'numeric', 'gt:0', 'max:744', 'decimal:0,2'],
            'labor_type' => ['nullable', Rule::in(['direct', 'indirect'])],
            'contract_type' => ['nullable', Rule::in(['monthly', 'hourly', 'piecework', 'daily'])],
        ]);
        $cost = $this->catalog->addLaborCost($resource, $data, $request->user());

        return response()->json(['data' => $this->laborData($cost), 'resource' => new ResourceDataResource($resource->fresh()->load(self::RELATIONS))], 201);
    }

    public function destroyLaborCost(Request $request, string $uuid, int $id): JsonResponse
    {
        $resource = $this->find($uuid);
        $this->catalog->deleteLaborCost(ResourceLaborCost::query()->where('resource_id', $resource->id)->findOrFail($id), $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ importação (D-P43)

    /** Pré-visualização: nada é gravado. `kind` material (product.xlsx) ou equipment (equipment.xlsx). */
    public function importPreview(Request $request, ResourceSheetImporter $importer): JsonResponse
    {
        $data = $this->importInput($request);

        return response()->json(['data' => $importer->preview($data['file']->getRealPath(), $data['kind'])]);
    }

    /** Executa a importação do MESMO arquivo revisado na pré-visualização (só as linhas válidas). */
    public function import(Request $request, ResourceSheetImporter $importer): JsonResponse
    {
        $data = $this->importInput($request);
        $summary = $importer->import($data['file']->getRealPath(), $data['kind'], $data['file']->getClientOriginalName(), $request->user());

        return response()->json(['data' => $summary], 201);
    }

    private function importInput(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(['material', 'equipment'])],
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx'],
        ]);
    }

    private function find(string $uuid): Resource
    {
        return Resource::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function laborData(ResourceLaborCost $c): array
    {
        return [
            'id' => $c->id, 'effective_date' => $c->effective_date?->toDateString(), 'base_salary' => $c->base_salary, 'extra_hour' => $c->extra_hour,
            'charges_percent' => $c->charges_percent, 'admin_percent' => $c->admin_percent, 'monthly_hours' => $c->monthly_hours,
            'labor_type' => $c->labor_type, 'contract_type' => $c->contract_type, 'hourly_cost' => $c->hourly_cost,
            'current' => $c->effective_date?->lte(now()) ?? false,
        ];
    }
}
