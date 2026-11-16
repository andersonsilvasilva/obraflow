<?php

namespace App\Modules\Teams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Equipment;
use App\Modules\Registry\Http\Controllers\Concerns\ListsRecords;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Teams\Http\Requests\EquipmentRequest;
use App\Modules\Teams\Http\Resources\EquipmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Equipamentos por empresa (sem custo — Fase 4). Soft delete. Filtros do padrão de listagem (L1): busca, grupo, situação. */
class EquipmentController extends Controller
{
    use ListsRecords;

    private const RELATIONS = ['company', 'group', 'unit', 'mainSupplier'];

    public function __construct(private readonly RegistryService $registry) {}

    public function index(Request $request): JsonResponse
    {
        $query = Equipment::query()->with(self::RELATIONS)->orderBy('name');
        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $like = '%'.addcslashes(mb_substr($term, 0, 100), '%_\\').'%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('brand', 'like', $like));
        }
        foreach (['status', 'ownership', 'group_id', 'unit_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('company_uuid')) {
            $query->where('company_id', Company::query()->where('uuid', $request->query('company_uuid'))->value('id') ?? 0);
        }

        return $this->paginated($query, $request, EquipmentResource::class);
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json(['data' => new EquipmentResource($this->find($uuid)->load(self::RELATIONS))]);
    }

    public function store(EquipmentRequest $request): JsonResponse
    {
        $equipment = $this->registry->create(new Equipment, $request->payload(), $request->user());

        return response()->json(['data' => new EquipmentResource($equipment->load(self::RELATIONS))], 201);
    }

    public function update(EquipmentRequest $request, string $uuid): JsonResponse
    {
        $equipment = $this->registry->update($this->find($uuid), $request->payload(), $request->user());

        return response()->json(['data' => new EquipmentResource($equipment->load(self::RELATIONS))]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->registry->delete($this->find($uuid), $request->user());

        return response()->json(null, 204);
    }

    private function find(string $uuid): Equipment
    {
        return Equipment::query()->where('uuid', $uuid)->firstOrFail();
    }
}
