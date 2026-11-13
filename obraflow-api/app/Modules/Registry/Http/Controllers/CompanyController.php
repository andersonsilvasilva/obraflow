<?php

namespace App\Modules\Registry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Modules\Registry\Http\Controllers\Concerns\ListsRecords;
use App\Modules\Registry\Http\Requests\BranchRequest;
use App\Modules\Registry\Http\Requests\CompanyRequest;
use App\Modules\Registry\Http\Resources\BranchResource;
use App\Modules\Registry\Http\Resources\CompanyResource;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Shared\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Empresas e filiais. Todas as consultas passam pelo escopo do tenant ativo (BelongsToTenant): registro de
 * outro tenant responde 404, igual a inexistente.
 */
class CompanyController extends Controller
{
    use ListsRecords;

    public function __construct(private readonly RegistryService $registry) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->search(Company::query(), $request, ['legal_name', 'trade_name'])->orderBy('legal_name');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return $this->paginated($query, $request, CompanyResource::class);
    }

    public function show(string $uuid): JsonResponse
    {
        $company = $this->find($uuid)->load(['branches', 'addresses', 'contacts']);

        return response()->json(['data' => new CompanyResource($company)]);
    }

    public function store(CompanyRequest $request): JsonResponse
    {
        $company = $this->registry->create(new Company, $request->validated(), $request->user());

        return response()->json(['data' => new CompanyResource($company)], 201);
    }

    public function update(CompanyRequest $request, string $uuid): JsonResponse
    {
        $company = $this->registry->update($this->find($uuid), $request->validated(), $request->user());

        return response()->json(['data' => new CompanyResource($company)]);
    }

    /** Soft delete. Recusa (409) se houver filial, cliente ou fornecedor ativo vinculado. */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $company = $this->find($uuid);

        if ($company->branches()->exists() || $company->customers()->exists() || $company->suppliers()->exists()) {
            throw ApiException::conflict('company_in_use', 'A empresa possui filiais, clientes ou fornecedores ativos.');
        }

        $this->registry->delete($company, $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ filiais

    public function branches(Request $request, string $uuid): JsonResponse
    {
        $branches = $this->find($uuid)->branches()->orderBy('name')->get();

        return response()->json(['data' => BranchResource::collection($branches)->resolve($request)]);
    }

    public function storeBranch(BranchRequest $request, string $uuid): JsonResponse
    {
        $company = $this->find($uuid);
        $branch = $this->registry->create(new CompanyBranch(['company_id' => $company->id]), $request->validated(), $request->user());

        return response()->json(['data' => new BranchResource($branch->setRelation('company', $company))], 201);
    }

    public function showBranch(int $id): JsonResponse
    {
        $branch = CompanyBranch::query()->with(['company', 'addresses', 'contacts'])->findOrFail($id);

        return response()->json(['data' => new BranchResource($branch)]);
    }

    public function updateBranch(BranchRequest $request, int $id): JsonResponse
    {
        $branch = $this->registry->update(CompanyBranch::query()->findOrFail($id), $request->validated(), $request->user());

        return response()->json(['data' => new BranchResource($branch->load('company'))]);
    }

    public function destroyBranch(Request $request, int $id): JsonResponse
    {
        $this->registry->delete(CompanyBranch::query()->findOrFail($id), $request->user());

        return response()->json(null, 204);
    }

    private function find(string $uuid): Company
    {
        return Company::query()->where('uuid', $uuid)->firstOrFail();
    }
}
