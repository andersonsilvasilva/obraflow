<?php

namespace App\Modules\Projects\Http\Requests;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Models\UserProjectAccess;
use App\Modules\Teams\Http\Requests\TenantRequest;
use Illuminate\Validation\Rule;

/**
 * Detalhes da obra, conforme o nome da rota: fases, equipe, mudança de status e acesso.
 * A obra em si é resolvida e autorizada no controller (ProjectAccess); aqui só a forma dos dados.
 */
class ProjectDetailRequest extends TenantRequest
{
    protected array $uuidRefs = [
        'employee_uuid' => [Employee::class, 'employee_id'],
        'user_uuid' => [User::class, 'user_id'],
    ];

    public function rules(): array
    {
        $name = (string) $this->route()?->getName();
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';
        $projectId = Project::query()->where('uuid', $this->route('uuid'))->value('id') ?? 0;
        $id = $this->route('id') ? (int) $this->route('id') : null;
        $phase = $id && str_contains($name, 'phases') ? ProjectPhase::query()->where('project_id', $projectId)->find($id) : null;
        $member = $id && str_contains($name, 'members') ? ProjectMember::query()->where('project_id', $projectId)->find($id) : null;

        return match (true) {
            str_ends_with($name, 'projects.phases.store'), str_ends_with($name, 'projects.phases.update') => [
                'name' => [$req, 'string', 'max:150'],
                'parent_id' => ['nullable', 'integer',
                    Rule::exists('project_phases', 'id')->where('tenant_id', $this->tenantId())->where('project_id', $projectId)],
                'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
                'planned_start' => ['nullable', 'date'],
                'planned_end' => array_filter(['nullable', 'date', $this->notBefore('planned_start', $phase?->planned_start)]),
            ],
            str_ends_with($name, 'projects.members.store'), str_ends_with($name, 'projects.members.update') => [
                'employee_uuid' => [$creating ? 'required' : 'prohibited', 'uuid', $this->existsInTenant('employees')],
                'role' => ['nullable', 'string', 'max:60'],
                'started_on' => [$req, 'date'],
                'ended_on' => array_filter(['nullable', 'date', $this->notBefore('started_on', $member?->started_on)]),
            ],
            str_ends_with($name, 'projects.status') => [
                'status' => ['required', Rule::in(Project::STATUSES)],
                'reason' => ['nullable', 'string', 'max:500'],
            ],
            str_ends_with($name, 'projects.access.store') => [
                'user_uuid' => ['required', 'uuid',
                    Rule::exists('users', 'uuid')->where(fn ($q) => $q->whereIn('id', fn ($m) => $m->select('user_id')
                        ->from('tenant_users')->where('tenant_id', $this->tenantId())->where('membership_status', 'active')))],
                'access_level' => ['required', Rule::in(UserProjectAccess::LEVELS)],
            ],
            default => [],
        };
    }
}
