<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectStatusHistory;
use App\Models\User;
use App\Models\UserProjectAccess;
use App\Modules\Projects\Http\Requests\ProjectDetailRequest;
use App\Modules\Projects\Http\Requests\ProjectRequest;
use App\Modules\Projects\Http\Resources\ProjectResource;
use App\Modules\Projects\Services\ProjectAccess;
use App\Modules\Projects\Services\ProjectAccessManager;
use App\Modules\Projects\Services\ProjectCodeGenerator;
use App\Modules\Projects\Services\ProjectStatusService;
use App\Modules\Registry\Http\Controllers\Concerns\ListsRecords;
use App\Modules\Registry\Http\Requests\AddressRequest;
use App\Modules\Registry\Http\Resources\AddressResource;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Obras e seus detalhes (endereço, fases, equipe, status, acesso). Autorização em duas camadas:
 * a rota exige `projects.view` (ou `projects.manage` para criar/excluir) e o ProjectAccess aplica o nível na obra
 * (D-P24). Obra que o usuário não pode ver responde 404, igual a inexistente ou de outro tenant.
 */
class ProjectController extends Controller
{
    use ListsRecords;

    private const RELATIONS = ['company', 'customer', 'technicalManager', 'foreman', 'siteAddress'];

    /** Ativas = em andamento ou a iniciar; inativas = encerradas (aba do painel, A-46). */
    private const ACTIVE = ['planning', 'in_progress', 'paused', 'delayed'];

    public function __construct(
        private readonly RegistryService $registry,
        private readonly ProjectAccess $access,
        private readonly ProjectStatusService $status,
        private readonly ProjectAccessManager $grants,
        private readonly ProjectCodeGenerator $codes,
    ) {}

    /** Próximo código automático (OB-NNN/AAAA) para pré-preencher a tela; `start_date` define o ano. */
    public function nextCode(Request $request): JsonResponse
    {
        $year = ProjectCodeGenerator::yearOf($request->filled('start_date') ? (string) $request->query('start_date') : null);

        return response()->json(['data' => ['code' => $this->codes->suggest($year), 'year' => $year]]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->access->visible(Project::query()->with(self::RELATIONS)->withCount(['members as active_members' => fn ($q) => $q->whereNull('ended_on')]), $request->user())->orderBy('code');
        if (in_array($request->query('situation'), ['active', 'inactive'], true)) {
            $request->query('situation') === 'active' ? $query->whereIn('status', self::ACTIVE) : $query->whereNotIn('status', self::ACTIVE);
        }
        $this->search($query, $request, ['code', 'name'], digitsColumn: null);
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        foreach (['company_uuid' => [Company::class, 'company_id'], 'customer_uuid' => [Customer::class, 'customer_id'],
            'technical_manager_uuid' => [Employee::class, 'technical_manager_id'], 'foreman_uuid' => [Employee::class, 'foreman_id']] as $param => [$model, $column]) {
            if ($request->filled($param)) {
                $query->where($column, $model::query()->where('uuid', $request->query($param))->value('id') ?? 0);
            }
        }

        $response = $this->paginated($query, $request, ProjectResource::class);
        $payload = $response->getData(true);
        $levels = $this->levels($request->user(), array_column($payload['data'], 'uuid'));
        foreach ($payload['data'] as &$row) {
            $row['access_level'] = $levels[$row['uuid']] ?? null;
        }

        return response()->json($payload);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'view')->load([...self::RELATIONS, 'addresses', 'phases', 'members.employee'])
            ->loadCount(['members as active_members' => fn ($q) => $q->whereNull('ended_on')]);

        return $this->respond($request, $project);
    }

    public function store(ProjectRequest $request): JsonResponse
    {
        $project = new Project;
        $project->created_by = $request->user()->getKey();
        $data = $request->payload();
        $site = $this->pullSiteAddress($data);
        DB::transaction(function () use ($project, &$data, $request, $site) {
            if (blank($data['code'] ?? null)) { // código automático com o ano da obra
                $data['code'] = $this->codes->next(ProjectCodeGenerator::yearOf($data['start_date'] ?? null));
            }
            $this->registry->create($project, $this->withDeliveryDate($project, $data), $request->user());
            $this->saveSiteAddress($project, $site, $request->user());
        });

        return $this->respond($request, $project->load(self::RELATIONS), 201);
    }

    public function update(ProjectRequest $request, string $uuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'edit');
        $data = $request->payload();
        $site = $this->pullSiteAddress($data);
        DB::transaction(function () use ($project, $data, $site, $request) {
            $this->registry->update($project, $this->withDeliveryDate($project, $data), $request->user());
            $this->saveSiteAddress($project, $site, $request->user());
        });

        return $this->respond($request, $project->load(self::RELATIONS));
    }

    /** Soft delete (rota exige projects.manage). */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->registry->delete($this->find($request, $uuid, 'manage'), $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ status (D-P27)

    public function changeStatus(ProjectDetailRequest $request, string $uuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'edit');
        $this->status->change($project, $request->validated('status'), $request->validated('reason'), $request->user());

        return $this->respond($request, $project->load(self::RELATIONS));
    }

    public function statusHistory(Request $request, string $uuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'view');
        $rows = ProjectStatusHistory::query()->where('project_id', $project->id)->with('changer:id,uuid,name')
            ->orderByDesc('changed_at')->orderByDesc('id')->get()
            ->map(fn ($h) => [
                'from_status' => $h->from_status, 'to_status' => $h->to_status, 'reason' => $h->reason,
                'changed_at' => $h->changed_at?->toIso8601String(), 'changed_by' => $h->changer?->only(['uuid', 'name']),
            ]);

        return response()->json(['data' => $rows]);
    }

    // ------------------------------------------------------------ endereço (padrão D-P12)

    public function addresses(Request $request, string $uuid): JsonResponse
    {
        $items = $this->find($request, $uuid, 'view')->addresses()->orderBy('id')->get();

        return response()->json(['data' => AddressResource::collection($items)->resolve($request)]);
    }

    public function storeAddress(AddressRequest $request, string $uuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'edit');
        $data = $request->validated() + ['kind' => 'site'];
        $this->guardSingleMain($project, $data['kind'], null);
        $address = $this->registry->create($project->addresses()->make(), $data, $request->user());

        return response()->json(['data' => new AddressResource($address)], 201);
    }

    public function updateAddress(AddressRequest $request, string $uuid, int $id): JsonResponse
    {
        $project = $this->find($request, $uuid, 'edit');
        $address = $project->addresses()->findOrFail($id);
        $this->guardSingleMain($project, $request->validated('kind', $address->kind), $address->id);
        $this->registry->update($address, $request->validated(), $request->user());

        return response()->json(['data' => new AddressResource($address)]);
    }

    public function destroyAddress(Request $request, string $uuid, int $id): JsonResponse
    {
        $this->registry->delete($this->find($request, $uuid, 'edit')->addresses()->findOrFail($id), $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ fases

    public function storePhase(ProjectDetailRequest $request, string $uuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'edit');
        $phase = $this->registry->create($project->phases()->make(), $request->validated(), $request->user());

        return response()->json(['data' => $phase->only(['id', 'parent_id', 'name', 'sort_order'])], 201);
    }

    public function updatePhase(ProjectDetailRequest $request, string $uuid, int $id): JsonResponse
    {
        $phase = $this->find($request, $uuid, 'edit')->phases()->findOrFail($id);
        $this->registry->update($phase, $request->validated(), $request->user());

        return response()->json(['data' => $phase->only(['id', 'parent_id', 'name', 'sort_order'])]);
    }

    /** Fase com subfases → 409 `in_use` (FK RESTRICT). */
    public function destroyPhase(Request $request, string $uuid, int $id): JsonResponse
    {
        $this->registry->delete($this->find($request, $uuid, 'edit')->phases()->findOrFail($id), $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ equipe

    public function storeMember(ProjectDetailRequest $request, string $uuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'edit');
        $member = $this->registry->create($project->members()->make(), $request->payload(), $request->user());

        return response()->json(['data' => $this->memberData($member)], 201);
    }

    public function updateMember(ProjectDetailRequest $request, string $uuid, int $id): JsonResponse
    {
        $member = $this->find($request, $uuid, 'edit')->members()->findOrFail($id);
        $this->registry->update($member, $request->payload(), $request->user());

        return response()->json(['data' => $this->memberData($member)]);
    }

    public function destroyMember(Request $request, string $uuid, int $id): JsonResponse
    {
        $this->registry->delete($this->find($request, $uuid, 'edit')->members()->findOrFail($id), $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ acesso por obra (D-P24)

    public function accessList(Request $request, string $uuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'manage');
        $rows = UserProjectAccess::query()->where('project_id', $project->id)->with('user:id,uuid,name,email')->get()
            ->map(fn ($a) => ['user' => $a->user?->only(['uuid', 'name', 'email']), 'access_level' => $a->access_level]);

        return response()->json(['data' => $rows]);
    }

    public function grantAccess(ProjectDetailRequest $request, string $uuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'manage');
        $data = $request->payload();
        $row = $this->grants->grant($project, (int) $data['user_id'], $data['access_level'], $request->user());

        return response()->json(['data' => ['user_uuid' => $request->validated('user_uuid'), 'access_level' => $row->access_level]]);
    }

    public function revokeAccess(Request $request, string $uuid, string $userUuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'manage');
        $userId = User::query()->where('uuid', $userUuid)->value('id');
        $row = UserProjectAccess::query()->where('project_id', $project->id)->where('user_id', $userId ?? 0)->firstOrFail();
        $this->grants->revoke($project, $row, $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ apoio

    private function find(Request $request, string $uuid, string $ability): Project
    {
        $project = Project::query()->where('uuid', $uuid)->firstOrFail();
        $level = $this->access->level($request->user(), $project);

        if ($level === null) {
            throw ApiException::notFound(); // não revela obra sem acesso
        }
        if (ProjectAccess::RANK[$level] < ProjectAccess::RANK[$ability]) {
            throw ApiException::forbidden('forbidden', 'Seu nível de acesso nesta obra não permite esta ação.');
        }

        return $project;
    }

    private function respond(Request $request, Project $project, int $status = 200): JsonResponse
    {
        $resource = (new ProjectResource($project))->withViewerLevel($this->access->level($request->user(), $project));

        return response()->json(['data' => $resource], $status);
    }

    /** @param  list<string>  $uuids  @return array<string, string> uuid => nível */
    private function levels(User $user, array $uuids): array
    {
        if ($user->can('projects.manage')) {
            return array_fill_keys($uuids, 'manage');
        }

        return UserProjectAccess::query()->join('projects', 'projects.id', '=', 'user_project_access.project_id')
            ->where('user_project_access.user_id', $user->getKey())->whereIn('projects.uuid', $uuids)
            ->pluck('user_project_access.access_level', 'projects.uuid')->all();
    }

    /** Tira o endereço do canteiro dos dados da obra (vai para project_addresses). Vazio = não mexe. */
    private function pullSiteAddress(array &$data): ?array
    {
        $site = $data['site_address'] ?? null;
        unset($data['site_address']);

        return is_array($site) && filled($site['street'] ?? null) ? $site : null;
    }

    /** Cria ou atualiza o endereço do canteiro (A-47); as coordenadas do mapa são preservadas se o endereço não mudou. */
    private function saveSiteAddress(Project $project, ?array $site, User $actor): void
    {
        if ($site === null) {
            return;
        }
        $fields = array_intersect_key($site, array_flip(['postal_code', 'street', 'number', 'complement', 'district', 'city', 'state']));
        $current = $project->siteAddress()->first();
        if ($current === null) {
            $this->registry->create($project->addresses()->make(), $fields + ['kind' => 'site'], $actor);

            return;
        }
        $moved = collect($fields)->some(fn ($v, $k) => (string) $v !== (string) $current->{$k});
        $this->registry->update($current, $moved ? $fields + ['latitude' => null, 'longitude' => null] : $fields, $actor);
    }

    /** Prazo em meses sem previsão de entrega informada: entrega = data inicial + prazo (A-47). */
    private function withDeliveryDate(Project $project, array $data): array
    {
        $months = $data['duration_months'] ?? null;
        $start = $data['start_date'] ?? $project->start_date?->toDateString();
        if ($months && $start && blank($data['expected_end_date'] ?? null) && ($project->expected_end_date === null || array_key_exists('duration_months', $data))) {
            $data['expected_end_date'] = CarbonImmutable::parse($start)->addMonthsNoOverflow((int) $months)->toDateString();
        }

        return $data;
    }

    private function guardSingleMain(Project $project, string $kind, ?int $ignoreId): void
    {
        if ($kind !== 'main') {
            return;
        }
        $exists = $project->addresses()->where('kind', 'main')->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
        if ($exists) {
            throw ApiException::conflict('main_address_exists', 'Já existe um endereço principal. Altere-o ou use outro tipo.');
        }
    }

    private function memberData($member): array
    {
        $member->load('employee');

        return [
            'id' => $member->id, 'employee' => $member->employee?->only(['uuid', 'name']), 'role' => $member->role,
            'started_on' => $member->started_on?->toDateString(), 'ended_on' => $member->ended_on?->toDateString(),
        ];
    }
}
