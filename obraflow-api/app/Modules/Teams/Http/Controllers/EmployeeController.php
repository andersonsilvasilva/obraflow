<?php

namespace App\Modules\Teams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Project;
use App\Modules\Registry\Http\Controllers\Concerns\ListsRecords;
use App\Modules\Registry\Services\DocumentAccess;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Teams\Http\Requests\CertificationRequest;
use App\Modules\Teams\Http\Requests\EmployeeRequest;
use App\Modules\Teams\Http\Resources\CertificationResource;
use App\Modules\Teams\Http\Resources\EmployeeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Funcionários e certificações. Escopo do tenant ativo em todas as consultas (outro tenant → 404).
 * Busca por nome, matrícula e — só para quem administra funcionários — CPF (D-P21).
 */
class EmployeeController extends Controller
{
    use ListsRecords;

    private const RELATIONS = ['company', 'jobTitle', 'department', 'supervisor', 'user'];

    public function __construct(private readonly RegistryService $registry, private readonly DocumentAccess $documents) {}

    public function index(Request $request): JsonResponse
    {
        $query = Employee::query()->with(self::RELATIONS)->orderBy('name');
        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $like = '%'.addcslashes(mb_substr($term, 0, 100), '%_\\').'%';
            $digits = preg_replace('/\D+/', '', $term);
            $query->where(function ($w) use ($like, $digits, $request) {
                $w->where('name', 'like', $like)->orWhere('registration_number', 'like', $like);
                if ($request->user()->can('employees.manage') && strlen($digits) >= 3) {
                    $w->orWhere('document_number', 'like', '%'.$digits.'%');
                }
            });
        }
        foreach (['status', 'job_title_id', 'department_id', 'labor_type', 'contract_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('company_uuid')) {
            $query->where('company_id', Company::query()->where('uuid', $request->query('company_uuid'))->value('id') ?? 0);
        }

        return $this->paginated($query, $request, EmployeeResource::class);
    }

    public function show(string $uuid): JsonResponse
    {
        $employee = $this->find($uuid)->load([...self::RELATIONS, 'certifications.document']);

        return response()->json(['data' => new EmployeeResource($employee)]);
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        $employee = $this->registry->create(new Employee, $request->payload(), $request->user());

        return response()->json(['data' => new EmployeeResource($employee->load(self::RELATIONS))], 201);
    }

    public function update(EmployeeRequest $request, string $uuid): JsonResponse
    {
        $employee = $this->registry->update($this->find($uuid), $request->payload(), $request->user());

        return response()->json(['data' => new EmployeeResource($employee->load(self::RELATIONS))]);
    }

    /** Soft delete. Recusa (409) se for responsável técnico de obra ativa ou estiver em equipe de obra em aberto. */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $employee = $this->find($uuid);
        $isManager = Project::query()->where('technical_manager_id', $employee->id)->exists();
        $inTeam = $employee->projectMemberships()->whereNull('ended_on')->exists();
        if ($isManager || $inTeam) {
            throw ApiException::conflict('employee_in_use', 'O funcionário é responsável técnico ou está na equipe de uma obra.');
        }

        $this->registry->delete($employee, $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ certificações

    public function certifications(Request $request, string $uuid): JsonResponse
    {
        $items = $this->find($uuid)->certifications()->with('document')->orderBy('expires_at')->get();

        return response()->json(['data' => CertificationResource::collection($items)->resolve($request)]);
    }

    public function storeCertification(CertificationRequest $request, string $uuid): JsonResponse
    {
        $employee = $this->find($uuid);
        $data = $this->withDocumentAccess($request);
        $cert = $this->registry->create($employee->certifications()->make(), $data, $request->user());

        return response()->json(['data' => new CertificationResource($cert->load('document'))], 201);
    }

    public function updateCertification(CertificationRequest $request, string $uuid, int $id): JsonResponse
    {
        $cert = $this->find($uuid)->certifications()->findOrFail($id);
        $cert = $this->registry->update($cert, $this->withDocumentAccess($request), $request->user());

        return response()->json(['data' => new CertificationResource($cert->load('document'))]);
    }

    public function destroyCertification(Request $request, string $uuid, int $id): JsonResponse
    {
        $this->registry->delete($this->find($uuid)->certifications()->findOrFail($id), $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ apoio

    private function find(string $uuid): Employee
    {
        return Employee::query()->where('uuid', $uuid)->firstOrFail();
    }

    /** Comprovante: o usuário precisa poder VER o documento (senão responde como inexistente). */
    private function withDocumentAccess(CertificationRequest $request): array
    {
        $data = $request->payload();
        if (! empty($data['document_id'])) {
            $document = Document::query()->findOrFail($data['document_id']);
            if (! $this->documents->allows($request->user(), $document, 'view')) {
                throw ApiException::notFound();
            }
        }

        return $data;
    }
}
