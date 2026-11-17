<?php

namespace App\Modules\Teams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ResourceGroup;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Teams\Http\Requests\ResourceGroupRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Grupos/subgrupos de recursos (D-P22, D-P40): equipamentos ou, em /catalog-groups, insumos do catálogo. Em uso → 409 `in_use`. */
class ResourceGroupController extends Controller
{
    private const FIELDS = ['id', 'kind', 'parent_id', 'name'];

    public function __construct(private readonly RegistryService $registry) {}

    /** Tipos atendidos pela rota: /resource-groups → equipment; /catalog-groups → material, labor, service (D-P40). */
    public static function kindsOf(Request $request): array
    {
        return $request->route('scope')['kinds'] ?? ['equipment'];
    }

    public function index(Request $request): JsonResponse
    {
        $kinds = self::kindsOf($request);
        $query = ResourceGroup::query()->whereIn('kind', $kinds)->orderByRaw('kind, parent_key, name');
        if ($request->filled('kind')) {
            $query->where('kind', $request->query('kind'));
        }
        $groups = $query->get(self::FIELDS);

        return response()->json(['data' => $groups]);
    }

    public function store(ResourceGroupRequest $request): JsonResponse
    {
        $group = $this->registry->create(new ResourceGroup, $request->payload(), $request->user());

        return response()->json(['data' => $group->only(self::FIELDS)], 201);
    }

    public function update(ResourceGroupRequest $request, int $id): JsonResponse
    {
        $group = $this->registry->update($this->find($request, $id), $request->payload(), $request->user());

        return response()->json(['data' => $group->only(self::FIELDS)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->registry->delete($this->find($request, $id), $request->user());

        return response()->json(null, 204);
    }

    private function find(Request $request, int $id): ResourceGroup
    {
        return ResourceGroup::query()->whereIn('kind', self::kindsOf($request))->findOrFail($id);
    }
}
