<?php

namespace App\Modules\Registry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\UnitOfMeasure;
use App\Modules\Registry\Http\Requests\UnitRequest;
use App\Modules\Registry\Http\Resources\UnitResource;
use App\Modules\Registry\Services\RegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Unidades de medida do tenant (D-P14). Sem exclusão: desative (status inactive) para preservar referências. */
class UnitController extends Controller
{
    public function __construct(private readonly RegistryService $registry) {}

    public function index(Request $request): JsonResponse
    {
        $query = UnitOfMeasure::query()->orderBy('code');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json(['data' => UnitResource::collection($query->get())->resolve($request)]);
    }

    public function store(UnitRequest $request): JsonResponse
    {
        $unit = $this->registry->create(new UnitOfMeasure, $request->validated(), $request->user());

        return response()->json(['data' => new UnitResource($unit)], 201);
    }

    public function update(UnitRequest $request, int $id): JsonResponse
    {
        $unit = $this->registry->update(UnitOfMeasure::query()->findOrFail($id), $request->validated(), $request->user());

        return response()->json(['data' => new UnitResource($unit)]);
    }
}
