<?php

use App\Models\Company;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\Equipment;
use App\Models\JobTitle;
use App\Models\Project;
use App\Models\ProjectAddress;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\ProjectStatusHistory;
use App\Models\ResourceGroup;
use App\Modules\Tenancy\Exceptions\MissingTenantContext;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Tests\Support\Scenario;

/* Fase 3 — modelos: escopo fail-closed, tenant_id do contexto, regras de aplicação e morph map. */

beforeEach(function () {
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
    $this->userA = Scenario::member($this->A);
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn, $tenant = null) => $this->ctx->run($tenant ?? $this->A, $fn);
    $this->co = ($this->in)(fn () => Company::create(['legal_name' => 'DEMO A', 'document_number' => '11222333000181']));
    $this->co2 = ($this->in)(fn () => Company::create(['legal_name' => 'DEMO A2', 'document_number' => '55666777000150']));
});

function novaObra(object $t, array $x = []): Project
{
    return ($t->in)(function () use ($t, $x) {
        $p = new Project(['company_id' => $t->co->id, 'code' => 'DEMO-'.uniqid(), 'name' => 'DEMO Obra', ...$x]);
        $p->created_by = $t->userA->id;
        $p->save();

        return $p;
    });
}

dataset('phase3_models', [
    JobTitle::class, Department::class, ResourceGroup::class, Employee::class, EmployeeCertification::class, Equipment::class,
    Project::class, ProjectAddress::class, ProjectPhase::class, ProjectStatusHistory::class, ProjectMember::class,
]);

it('consultar sem tenant ativo lança exceção (nunca devolve "tudo")', function (string $model) {
    expect(fn () => $model::query()->count())->toThrow(MissingTenantContext::class);
})->with('phase3_models');

it('tenant_id vem do contexto e ignora valor enviado; outro tenant não enxerga', function () {
    $emp = ($this->in)(fn () => Employee::create(['company_id' => $this->co->id, 'name' => 'DEMO Func', 'tenant_id' => $this->B->id]));

    expect($emp->tenant_id)->toBe($this->A->id)
        ->and(($this->in)(fn () => Employee::find($emp->id), $this->B))->toBeNull()
        ->and($emp->uuid)->not->toBeEmpty()
        ->and($emp->getRouteKeyName())->toBe('uuid');
});

it('CPF do funcionário é normalizado e pode ser mascarado (D-P21)', function () {
    $emp = ($this->in)(fn () => Employee::create(['company_id' => $this->co->id, 'name' => 'DEMO', 'document_number' => '529.982.247-25']));

    expect($emp->document_number)->toBe('52998224725')
        ->and($emp->maskedDocument())->toBe('***.982.247-**');
});

it('funcionário: responsável não pode ser ele mesmo nem de outra empresa', function () {
    [$chefe, $outro] = ($this->in)(fn () => [
        Employee::create(['company_id' => $this->co->id, 'name' => 'DEMO Chefe']),
        Employee::create(['company_id' => $this->co2->id, 'name' => 'DEMO Outro']),
    ]);

    expect(fn () => ($this->in)(fn () => $chefe->update(['supervisor_id' => $chefe->id])))->toThrow(LogicException::class)
        ->and(fn () => ($this->in)(fn () => Employee::create(['company_id' => $this->co->id, 'name' => 'x', 'supervisor_id' => $outro->id])))->toThrow(LogicException::class);

    $sub = ($this->in)(fn () => Employee::create(['company_id' => $this->co->id, 'name' => 'DEMO Sub', 'supervisor_id' => $chefe->id]));
    expect(($this->in)(fn () => $sub->supervisor)->is($chefe))->toBeTrue();
});

it('grupo de recursos: no máximo dois níveis; grupo com filhos não vira subgrupo', function () {
    [$g, $sub, $outro] = ($this->in)(function () {
        $g = ResourceGroup::create(['kind' => 'equipment', 'name' => 'DEMO Grupo']);
        $sub = ResourceGroup::create(['kind' => 'equipment', 'parent_id' => $g->id, 'name' => 'DEMO Sub']);
        $outro = ResourceGroup::create(['kind' => 'equipment', 'name' => 'DEMO Outro']);

        return [$g, $sub, $outro];
    });

    expect(fn () => ($this->in)(fn () => ResourceGroup::create(['kind' => 'equipment', 'parent_id' => $sub->id, 'name' => 'x'])))->toThrow(LogicException::class)
        ->and(fn () => ($this->in)(fn () => $g->update(['parent_id' => $outro->id])))->toThrow(LogicException::class)
        ->and(fn () => ($this->in)(fn () => $g->update(['parent_id' => $g->id])))->toThrow(LogicException::class);
});

it('equipamento: group_kind fixo e não preenchível; padrões de posse e situação', function () {
    $eq = ($this->in)(fn () => Equipment::create(['company_id' => $this->co->id, 'name' => 'DEMO Betoneira', 'group_kind' => 'material']));

    expect($eq->fresh()->group_kind)->toBe('equipment')
        ->and($eq->ownership)->toBe('owned')
        ->and($eq->status)->toBe('available')
        ->and($eq->toArray())->not->toHaveKey('group_kind');
});

it('obra: status não é preenchível (só pelo serviço de transição); cliente precisa ser da mesma empresa', function () {
    $obra = novaObra($this, ['status' => 'completed']);
    expect($obra->fresh()->status)->toBe('planning')->and($obra->progress)->toBe(0);

    $clienteOutraEmpresa = ($this->in)(fn () => Customer::create(['company_id' => $this->co2->id, 'type' => 'person', 'name' => 'DEMO Cliente']));
    expect(fn () => novaObra($this, ['customer_id' => $clienteOutraEmpresa->id]))->toThrow(LogicException::class);
});

it('obra: transições de status permitidas (D-P27)', function () {
    expect(Project::canTransition('planning', 'in_progress'))->toBeTrue()
        ->and(Project::canTransition('planning', 'completed'))->toBeFalse()
        ->and(Project::canTransition('completed', 'in_progress'))->toBeFalse()
        ->and(Project::canTransition('canceled', 'planning'))->toBeFalse()
        ->and(array_keys(Project::TRANSITIONS))->toBe(Project::STATUSES);
});

it('fases: até 3 níveis e sem ciclo', function () {
    $obra = novaObra($this);
    [$n1, $n2, $n3] = ($this->in)(function () use ($obra) {
        $n1 = ProjectPhase::create(['project_id' => $obra->id, 'name' => 'DEMO 1']);
        $n2 = ProjectPhase::create(['project_id' => $obra->id, 'parent_id' => $n1->id, 'name' => 'DEMO 1.1']);
        $n3 = ProjectPhase::create(['project_id' => $obra->id, 'parent_id' => $n2->id, 'name' => 'DEMO 1.1.1']);

        return [$n1, $n2, $n3];
    });

    expect(fn () => ($this->in)(fn () => ProjectPhase::create(['project_id' => $obra->id, 'parent_id' => $n3->id, 'name' => 'x'])))->toThrow(LogicException::class)
        ->and(fn () => ($this->in)(fn () => $n1->update(['parent_id' => $n3->id])))->toThrow(LogicException::class)
        ->and(($this->in)(fn () => $n1->children()->count()))->toBe(1);
});

it('equipe: funcionário precisa ser da mesma empresa da obra', function () {
    $obra = novaObra($this);
    [$daCasa, $deFora] = ($this->in)(fn () => [
        Employee::create(['company_id' => $this->co->id, 'name' => 'DEMO Casa']),
        Employee::create(['company_id' => $this->co2->id, 'name' => 'DEMO Fora']),
    ]);

    $m = ($this->in)(fn () => ProjectMember::create(['project_id' => $obra->id, 'employee_id' => $daCasa->id, 'started_on' => '2026-01-01', 'role' => 'Mestre']));
    expect(($this->in)(fn () => $m->employee)->is($daCasa))->toBeTrue()
        ->and(fn () => ($this->in)(fn () => ProjectMember::create(['project_id' => $obra->id, 'employee_id' => $deFora->id, 'started_on' => '2026-01-01'])))->toThrow(LogicException::class);
});

it('histórico de status é append-only', function () {
    $obra = novaObra($this);
    $h = ($this->in)(function () use ($obra) {
        $h = new ProjectStatusHistory;
        $h->forceFill(['project_id' => $obra->id, 'from_status' => 'planning', 'to_status' => 'in_progress', 'changed_by' => $this->userA->id])->save();

        return $h;
    });

    expect(fn () => ($this->in)(fn () => ProjectStatusHistory::create(['project_id' => $obra->id, 'to_status' => 'paused'])))->toThrow(MassAssignmentException::class)
        ->and(fn () => ($this->in)(fn () => $h->forceFill(['reason' => 'x'])->save()))->toThrow(LogicException::class)
        ->and(fn () => ($this->in)(fn () => $h->delete()))->toThrow(LogicException::class)
        ->and(fn () => ($this->in)(fn () => ProjectStatusHistory::query()->update(['reason' => 'x'])))->toThrow(LogicException::class);
});

it('morph map da Fase 3 usa aliases curtos', function () {
    foreach (['job_title' => JobTitle::class, 'employee' => Employee::class, 'equipment' => Equipment::class, 'project' => Project::class, 'project_member' => ProjectMember::class] as $alias => $class) {
        expect(Relation::getMorphedModel($alias))->toBe($class)
            ->and((new $class)->getMorphClass())->toBe($alias);
    }
});
