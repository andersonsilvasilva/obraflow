<?php

namespace App\Modules\Registry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Supplier;
use App\Modules\Registry\Http\Controllers\Concerns\ListsRecords;
use App\Modules\Registry\Http\Requests\PartyRequest;
use App\Modules\Registry\Http\Resources\PartyResource;
use App\Modules\Registry\Services\RegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Base de clientes e fornecedores (mesmo fluxo; mudam o modelo e os campos de nome). */
abstract class PartyController extends Controller
{
    use ListsRecords;

    public function __construct(protected readonly RegistryService $registry) {}

    /** @return class-string<Customer|Supplier> */
    abstract protected function model(): string;

    /** @return list<string> colunas de nome usadas na busca e na ordenação (a primeira ordena) */
    abstract protected function nameColumns(): array;

    public function index(Request $request): JsonResponse
    {
        $model = $this->model();
        $query = $this->search($model::query()->with('company'), $request, $this->nameColumns())->orderBy($this->nameColumns()[0]);

        if ($request->filled('company_uuid')) {
            // empresa de outro tenant não é encontrada pelo escopo → filtro vazio (nunca vaza)
            $query->where('company_id', Company::query()->where('uuid', $request->query('company_uuid'))->value('id') ?? 0);
        }
        foreach (['type', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        return $this->paginated($query, $request, PartyResource::class);
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json(['data' => new PartyResource($this->find($uuid)->load(['company', 'addresses', 'contacts']))]);
    }

    public function store(PartyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $company = Company::query()->where('uuid', $data['company_uuid'])->firstOrFail();
        unset($data['company_uuid']);

        $model = $this->model();
        $party = $this->registry->create(new $model(['company_id' => $company->id]), $data, $request->user());

        return response()->json(['data' => new PartyResource($party->setRelation('company', $company))], 201);
    }

    public function update(PartyRequest $request, string $uuid): JsonResponse
    {
        $party = $this->registry->update($this->find($uuid), $request->validated(), $request->user());

        return response()->json(['data' => new PartyResource($party->load('company'))]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->registry->delete($this->find($uuid), $request->user());

        return response()->json(null, 204);
    }

    protected function find(string $uuid): Customer|Supplier
    {
        $model = $this->model();

        return $model::query()->where('uuid', $uuid)->firstOrFail();
    }
}
