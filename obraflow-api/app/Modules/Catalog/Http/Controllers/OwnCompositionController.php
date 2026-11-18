<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OwnComposition;
use App\Modules\Budgets\Support\Dec;
use App\Modules\Catalog\Http\Requests\OwnCompositionRequest;
use App\Modules\Registry\Http\Controllers\Concerns\ListsRecords;
use App\Modules\Registry\Services\RegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Composições próprias simplificadas da organização (6B, D-P87): Cadastros → Recursos → Composições próprias.
 * CRUD auditado (registry.*); exclusão lógica. Os totais por regime são calculados (não gravados).
 */
class OwnCompositionController extends Controller
{
    use ListsRecords;

    public function __construct(private readonly RegistryService $registry) {}

    public function index(Request $request): JsonResponse
    {
        $query = OwnComposition::query()->with('unit:id,code,name')->orderBy('name');
        $this->search($query, $request, ['name', 'code'], digitsColumn: null);
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return $this->paginated($query, $request, OwnCompositionResource::class);
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json(['data' => self::data($this->find($uuid)->load('unit:id,code,name'))]);
    }

    public function store(OwnCompositionRequest $request): JsonResponse
    {
        $c = $this->registry->create(new OwnComposition, $request->validated(), $request->user());

        return response()->json(['data' => self::data($c->load('unit:id,code,name'))], 201);
    }

    public function update(OwnCompositionRequest $request, string $uuid): JsonResponse
    {
        $c = $this->registry->update($this->find($uuid), $request->validated(), $request->user());

        return response()->json(['data' => self::data($c->load('unit:id,code,name'))]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->registry->delete($this->find($uuid), $request->user());

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    public static function data(OwnComposition $c): array
    {
        $totals = [];
        foreach (OwnComposition::REGIME_SUFFIX as $regime => $_) {
            $totals[$regime] = Dec::round(Dec::add('0', ...array_values($c->costsFor($regime))));
        }

        return [
            'uuid' => $c->uuid,
            'code' => $c->code,
            'name' => $c->name,
            'unit' => $c->relationLoaded('unit') ? $c->unit?->only(['id', 'code', 'name']) : null,
            'reference_date' => $c->reference_date?->toDateString(),
            'state' => $c->state,
            ...array_combine(OwnComposition::VALUES, array_map(fn ($k) => (string) $c->{$k}, OwnComposition::VALUES)),
            'totals' => $totals, // por regime: not_applicable (sem encargos), with_relief (desonerado), without_relief (não desonerado)
            'notes' => $c->notes,
            'status' => $c->status,
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    private function find(string $uuid): OwnComposition
    {
        return OwnComposition::query()->where('uuid', $uuid)->firstOrFail();
    }
}
