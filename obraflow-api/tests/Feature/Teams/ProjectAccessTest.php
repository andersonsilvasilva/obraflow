<?php

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\UserProjectAccess;
use App\Modules\Projects\Services\ProjectAccess;
use App\Modules\Tenancy\TenantContext;
use Tests\Support\Scenario;

/*
 * Fase 3 — etapa 4: acesso por obra (D-P24).
 * projects.manage vê tudo; sem projects.view nada; os demais só as obras liberadas, limitados pelo nível.
 */

beforeEach(function () {
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
    $this->admin = Scenario::member($this->A, 'admin');
    $this->member = Scenario::member($this->A, 'member');
    $this->viewer = Scenario::member($this->A, 'viewer');
    $this->access = app(ProjectAccess::class);

    $this->as = fn (User $u, callable $fn, $tenant = null) => app(TenantContext::class)->run($tenant ?? $this->A, $fn, $u);

    [$this->o1, $this->o2, $this->o3] = ($this->as)($this->admin, function () {
        $co = Company::create(['legal_name' => 'DEMO', 'document_number' => '11222333000181']);
        $mk = function (string $code) use ($co) {
            $p = new Project(['company_id' => $co->id, 'code' => $code, 'name' => "DEMO {$code}"]);
            $p->created_by = $this->admin->id;
            $p->save();

            return $p;
        };

        return [$mk('DEMO-1'), $mk('DEMO-2'), $mk('DEMO-3')];
    });
});

function grant(object $t, User $u, Project $p, string $level): void
{
    ($t->as)($t->admin, fn () => UserProjectAccess::create([
        'user_id' => $u->id, 'project_id' => $p->id, 'access_level' => $level, 'granted_by' => $t->admin->id,
    ]));
}

function visibleCodes(object $t, User $u): array
{
    return ($t->as)($u, fn () => $t->access->visible(Project::query(), $u)->orderBy('code')->pluck('code')->all());
}

it('projects.manage (admin) vê e administra todas as obras, sem linha de acesso', function () {
    expect(visibleCodes($this, $this->admin))->toBe(['DEMO-1', 'DEMO-2', 'DEMO-3'])
        ->and(($this->as)($this->admin, fn () => $this->access->level($this->admin, $this->o2)))->toBe('manage');
});

it('member (projects.view) só vê as obras liberadas, com o nível concedido', function () {
    expect(visibleCodes($this, $this->member))->toBe([]);

    grant($this, $this->member, $this->o1, 'view');
    grant($this, $this->member, $this->o3, 'edit');

    expect(visibleCodes($this, $this->member))->toBe(['DEMO-1', 'DEMO-3'])
        ->and(($this->as)($this->member, fn () => [
            $this->access->allows($this->member, $this->o1, 'view'),
            $this->access->allows($this->member, $this->o1, 'edit'),
            $this->access->allows($this->member, $this->o3, 'edit'),
            $this->access->allows($this->member, $this->o3, 'manage'),
            $this->access->level($this->member, $this->o2),
        ]))->toBe([true, false, true, false, null]);
});

it('sem projects.view (viewer) não vê nenhuma obra, mesmo com linha de acesso', function () {
    grant($this, $this->viewer, $this->o1, 'manage');

    expect(visibleCodes($this, $this->viewer))->toBe([])
        ->and(($this->as)($this->viewer, fn () => $this->access->allows($this->viewer, $this->o1, 'view')))->toBeFalse();
});

it('acesso de um tenant não vaza para outro: no tenant B o usuário não enxerga obras de A', function () {
    grant($this, $this->member, $this->o1, 'manage');
    Scenario::attach($this->B, $this->member, 'admin'); // admin em B

    $emB = ($this->as)($this->member, fn () => $this->access->visible(Project::query(), $this->member)->count(), $this->B);
    expect($emB)->toBe(0);
});

it('papel member de tenants novos recebe projects.view e equipment.view, mas não employees.view (D-P28)', function () {
    expect(($this->as)($this->member, fn () => [
        $this->member->can('projects.view'), $this->member->can('equipment.view'),
        $this->member->can('employees.view'), $this->member->can('projects.manage'),
    ]))->toBe([true, true, false, false]);
});
