<?php

namespace App\Modules\Teams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ResourceGroup;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Teams\Http\Requests\ResourceGroupRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Grupos/subgrupos de equipamentos (D-P22). Exclusão de grupo com subgrupos ou equipamentos → 409 `in_use`. */
class ResourceGroupController extends Controller
{
    private const FIELDS = ['id', 'kind', 'parent_id', 'name'];

    public function __construct(private readonly RegistryService $registry) {}

    public function index(): JsonResponse
    {
        $groups = ResourceGroup::query()->where('kind', 'equipment')->orderByRaw('parent_key, name')->get(self::FIELDS);

        return response()->json(['data' => $groups]);
    }

    public function store(ResourceGroupRequest $request): JsonResponse
    {
        $group = $this->registry->create(new ResourceGroup, $request->payload(), $request->user());

        return response()->json(['data' => $group->only(self::FIELDS)], 201);
    }

    public function update(ResourceGroupRequest $request, int $id): JsonResponse
    {
        $group = $this->registry->update($this->find($id), $request->payload(), $request->user());

        return response()->json(['data' => $group->only(self::FIELDS)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->registry->delete($this->find($id), $request->user());

        return response()->json(null, 204);
    }

    private function find(int $id): ResourceGroup
    {
        return ResourceGroup::query()->where('kind', 'equipment')->findOrFail($id);
    }
}
