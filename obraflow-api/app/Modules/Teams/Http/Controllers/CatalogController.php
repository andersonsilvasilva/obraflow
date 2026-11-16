<?php

namespace App\Modules\Teams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\JobTitle;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Teams\Http\Requests\CatalogRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cargos/funções e setores (D-P20) — cadastros simples por tenant. A rota informa o catálogo (`catalog` via defaults).
 * Exclusão física; em uso por funcionário → 409 `in_use` (FK RESTRICT). Prefira desativar.
 */
class CatalogController extends Controller
{
    private const MODELS = ['job_titles' => JobTitle::class, 'departments' => Department::class];

    public function __construct(private readonly RegistryService $registry) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->model($request)::query()->orderBy('name');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json(['data' => $query->get(['id', 'name', 'status'])]);
    }

    public function store(CatalogRequest $request): JsonResponse
    {
        $class = $this->model($request);
        $item = $this->registry->create(new $class, $request->validated(), $request->user());

        return response()->json(['data' => $item->only(['id', 'name', 'status'])], 201);
    }

    public function update(CatalogRequest $request, int $id): JsonResponse
    {
        $item = $this->registry->update($this->find($request, $id), $request->validated(), $request->user());

        return response()->json(['data' => $item->only(['id', 'name', 'status'])]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->registry->delete($this->find($request, $id), $request->user());

        return response()->json(null, 204);
    }

    /** @return class-string<Model> */
    private function model(Request $request): string
    {
        return self::MODELS[$request->route('catalog')];
    }

    private function find(Request $request, int $id): Model
    {
        return $this->model($request)::query()->findOrFail($id);
    }
}
