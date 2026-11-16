<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\Fx;

/*
 * Fase 3 — etapas 1 a 3: cadastros auxiliares, funcionários, certificações, equipamentos e obras.
 * O banco é a última linha de defesa: FK composta recusa vínculo entre tenants/empresas/obras (1452),
 * CHECK recusa domínio inválido (3819), UNIQUE recusa duplicidade (1062), FK recusa excluir em uso (1451).
 */

beforeEach(function () {
    $this->A = Fx::tenant();
    $this->B = Fx::tenant();
    $this->coA = Fx::company($this->A);
    $this->coA2 = Fx::company($this->A, '55666777000150');
    $this->coB = Fx::company($this->B);
    $this->user = Fx::user();
    Fx::member($this->A, $this->user);
    Fx::member($this->B, $this->user);
});

// ---------------------------------------------------------------- cargos, setores, grupos
it('cargo e setor são por tenant: nome único no tenant, repetível em outro', function () {
    foreach (['jobTitle', 'department'] as $fn) {
        expect(Fx::errno(fn () => Fx::$fn($this->A, 'Pedreiro')))->toBeNull()
            ->and(Fx::errno(fn () => Fx::$fn($this->B, 'Pedreiro')))->toBeNull()
            ->and(Fx::errno(fn () => Fx::$fn($this->A, 'Pedreiro')))->toBe(1062);
    }
    expect(Fx::errno(fn () => DB::table('job_titles')->insert(['tenant_id' => $this->A, 'name' => 'x', 'status' => 'blocked'])))->toBe(3819);
});

it('grupo de recursos: subgrupo só sob pai do mesmo tenant E do mesmo tipo; nome único também na raiz', function () {
    $g = Fx::group($this->A, 'equipment', null, 'Andaimes');

    expect(Fx::errno(fn () => Fx::group($this->A, 'equipment', $g, 'Tubular')))->toBeNull()
        ->and(Fx::errno(fn () => Fx::group($this->A, 'material', $g, 'Tubular')))->toBe(1452) // tipo diferente do pai
        ->and(Fx::errno(fn () => Fx::group($this->B, 'equipment', $g, 'Tubular')))->toBe(1452) // pai de outro tenant
        ->and(Fx::errno(fn () => Fx::group($this->A, 'equipment', null, 'Andaimes')))->toBe(1062) // raiz duplicada (parent_key)
        ->and(Fx::errno(fn () => Fx::group($this->A, 'material', null, 'Andaimes')))->toBeNull()
        ->and(Fx::errno(fn () => Fx::group($this->A, 'tool', null, 'x')))->toBe(3819);
});

// ---------------------------------------------------------------- funcionários
it('funcionário só pertence a empresa, cargo, setor e responsável do mesmo tenant', function () {
    $jtB = Fx::jobTitle($this->B);
    $deptB = Fx::department($this->B);
    $empB = Fx::employee($this->B, $this->coB);

    expect(Fx::errno(fn () => Fx::employee($this->A, $this->coB)))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['job_title_id' => $jtB])))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['department_id' => $deptB])))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['supervisor_id' => $empB])))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['job_title_id' => Fx::jobTitle($this->A)])))->toBeNull();
});

it('usuário ligado ao funcionário precisa ser membro do tenant (FK → tenant_users) e só uma vez', function () {
    $estranho = Fx::user();
    $soDeA = Fx::user();
    Fx::member($this->A, $soDeA);

    expect(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['user_id' => $estranho])))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::employee($this->B, $this->coB, ['user_id' => $soDeA])))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['user_id' => $this->user])))->toBeNull()
        ->and(Fx::errno(fn () => Fx::employee($this->A, $this->coA2, ['user_id' => $this->user])))->toBe(1062);
});

it('CPF e matrícula: opcionais, só dígitos, únicos entre os ativos da empresa; excluído pode ser recadastrado', function () {
    $id = Fx::employee($this->A, $this->coA, ['document_number' => '52998224725', 'registration_number' => 'M-1']);

    expect(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['document_number' => '52998224725'])))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['registration_number' => 'M-1'])))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::employee($this->A, $this->coA2, ['document_number' => '52998224725', 'registration_number' => 'M-1'])))->toBeNull()
        ->and(Fx::errno(fn () => Fx::employee($this->A, $this->coA)))->toBeNull() // CPF NULL não conflita
        ->and(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['document_number' => '5299822472X'])))->toBe(3819);

    DB::table('employees')->where('id', $id)->update(['deleted_at' => now()]);
    expect(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['document_number' => '52998224725', 'registration_number' => 'M-1'])))->toBeNull();
});

it('domínios do funcionário: tipo de mão de obra, contrato, status e datas (CHECK)', function () {
    expect(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['labor_type' => 'direct', 'contract_type' => 'daily'])))->toBeNull()
        ->and(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['labor_type' => 'outsourced'])))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['contract_type' => 'weekly'])))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['status' => 'fired'])))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::employee($this->A, $this->coA, ['hired_at' => '2026-05-01', 'terminated_at' => '2026-04-30'])))->toBe(3819);
});

it('certificação: funcionário e comprovante do mesmo tenant; vencimento após emissão', function () {
    $emp = Fx::employee($this->A, $this->coA);
    $docB = Fx::document($this->B, $this->user);
    $ins = fn (array $x) => DB::table('employee_certifications')->insert(['tenant_id' => $this->A, 'employee_id' => $emp, 'name' => 'NR-35', ...$x]);

    expect(Fx::errno(fn () => $ins(['issued_at' => '2026-01-01', 'expires_at' => '2027-01-01'])))->toBeNull()
        ->and(Fx::errno(fn () => $ins(['document_id' => $docB])))->toBe(1452)
        ->and(Fx::errno(fn () => $ins(['document_id' => Fx::document($this->A, $this->user)])))->toBeNull()
        ->and(Fx::errno(fn () => $ins(['issued_at' => '2026-01-01', 'expires_at' => '2025-12-31'])))->toBe(3819)
        ->and(Fx::errno(fn () => DB::table('employee_certifications')->insert(['tenant_id' => $this->B, 'employee_id' => $emp, 'name' => 'x'])))->toBe(1452);
});

// ---------------------------------------------------------------- equipamentos
it('equipamento: empresa, unidade, fornecedor e grupo do mesmo tenant; grupo precisa ser de equipamentos', function () {
    $gMat = Fx::group($this->A, 'material');
    $gEq = Fx::group($this->A, 'equipment');
    $unitB = (int) DB::table('units_of_measure')->insertGetId(['tenant_id' => $this->B, 'code' => 'h', 'name' => 'hora']);
    $supB = Fx::supplier($this->B, $this->coB);

    expect(Fx::errno(fn () => Fx::equipment($this->A, $this->coA, ['group_id' => $gEq])))->toBeNull()
        ->and(Fx::errno(fn () => Fx::equipment($this->A, $this->coA, ['group_id' => $gMat])))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::equipment($this->A, $this->coA, ['group_id' => $gMat, 'group_kind' => 'material'])))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::equipment($this->A, $this->coB)))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::equipment($this->A, $this->coA, ['unit_id' => $unitB])))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::equipment($this->A, $this->coA, ['main_supplier_id' => $supB])))->toBe(1452);
});

it('equipamento: código único entre os ativos da empresa; posse e situação no domínio', function () {
    expect(Fx::errno(fn () => Fx::equipment($this->A, $this->coA, ['code' => 'EQ-1'])))->toBeNull()
        ->and(Fx::errno(fn () => Fx::equipment($this->A, $this->coA, ['code' => 'EQ-1'])))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::equipment($this->A, $this->coA2, ['code' => 'EQ-1'])))->toBeNull()
        ->and(Fx::errno(fn () => Fx::equipment($this->A, $this->coA, ['ownership' => 'leased'])))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::equipment($this->A, $this->coA, ['status' => 'lost'])))->toBe(3819);
});

it('grupo em uso não pode ser excluídos (RESTRICT)', function () {
    $g = Fx::group($this->A, 'equipment');
    Fx::equipment($this->A, $this->coA, ['group_id' => $g]);

    expect(Fx::errno(fn () => DB::table('resource_groups')->where('id', $g)->delete()))->toBe(1451);
});

// ---------------------------------------------------------------- obras
it('obra: empresa e cliente do mesmo tenant; código único entre as ativas do tenant', function () {
    $custB = Fx::customer($this->B, $this->coB);

    expect(Fx::errno(fn () => Fx::project($this->A, $this->coA, $this->user)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::project($this->A, $this->coA2, $this->user)))->toBe(1062) // código é por tenant
        ->and(Fx::errno(fn () => Fx::project($this->B, $this->coB, $this->user)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::project($this->A, $this->coB, $this->user, 'X-2')))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::project($this->A, $this->coA, $this->user, 'X-3', ['customer_id' => $custB])))->toBe(1452);
});

it('quem cria a obra e quem muda o status precisa ser membro do tenant (FK → tenant_users)', function () {
    $estranho = Fx::user();
    $obra = Fx::project($this->A, $this->coA, $this->user);

    expect(Fx::errno(fn () => Fx::project($this->A, $this->coA, $estranho, 'C-1')))->toBe(1452)
        ->and(Fx::errno(fn () => DB::table('project_status_history')->insert([
            'tenant_id' => $this->A, 'project_id' => $obra, 'to_status' => 'in_progress', 'changed_by' => $estranho,
        ])))->toBe(1452);
});

it('responsável técnico precisa ser funcionário da MESMA empresa da obra (FK tripla)', function () {
    $empA = Fx::employee($this->A, $this->coA);
    $empA2 = Fx::employee($this->A, $this->coA2);
    $empB = Fx::employee($this->B, $this->coB);

    expect(Fx::errno(fn () => Fx::project($this->A, $this->coA, $this->user, 'R-1', ['technical_manager_id' => $empA])))->toBeNull()
        ->and(Fx::errno(fn () => Fx::project($this->A, $this->coA, $this->user, 'R-2', ['technical_manager_id' => $empA2])))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::project($this->A, $this->coA, $this->user, 'R-3', ['technical_manager_id' => $empB])))->toBe(1452)
        ->and(Fx::errno(fn () => DB::table('employees')->where('id', $empA)->delete()))->toBe(1451);
});

it('obra: status, progresso, valor e datas no domínio (CHECK); código de obra excluída pode ser reutilizado', function () {
    $p = fn (string $code, array $x) => Fx::project($this->A, $this->coA, $this->user, $code, $x);

    expect(Fx::errno(fn () => $p('S-1', ['status' => 'archived'])))->toBe(3819)
        ->and(Fx::errno(fn () => $p('S-2', ['progress' => 101])))->toBe(3819)
        ->and(Fx::errno(fn () => $p('S-3', ['contract_value' => -1])))->toBe(3819)
        ->and(Fx::errno(fn () => $p('S-4', ['start_date' => '2026-05-01', 'expected_end_date' => '2026-04-01'])))->toBe(3819)
        ->and(Fx::errno(fn () => $p('S-5', ['start_date' => '2026-05-01', 'actual_end_date' => '2026-04-01'])))->toBe(3819);

    $id = $p('S-6', []);
    DB::table('projects')->where('id', $id)->update(['deleted_at' => now()]);
    expect(Fx::errno(fn () => $p('S-6', [])))->toBeNull();
});

it('endereço da obra: mesmo tenant, um único principal', function () {
    $obra = Fx::project($this->A, $this->coA, $this->user);
    $obraB = Fx::project($this->B, $this->coB, $this->user);

    expect(Fx::errno(fn () => Fx::address('project_addresses', 'project_id', $this->A, $obra)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::address('project_addresses', 'project_id', $this->A, $obra)))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::address('project_addresses', 'project_id', $this->A, $obra, ['kind' => 'site'])))->toBeNull()
        ->and(Fx::errno(fn () => Fx::address('project_addresses', 'project_id', $this->A, $obraB)))->toBe(1452);
});

it('fase: obra do mesmo tenant e fase-pai da MESMA obra; datas coerentes', function () {
    $o1 = Fx::project($this->A, $this->coA, $this->user, 'F-1');
    $o2 = Fx::project($this->A, $this->coA, $this->user, 'F-2');
    $raiz = Fx::phase($this->A, $o1);

    expect(Fx::errno(fn () => Fx::phase($this->A, $o1, $raiz)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::phase($this->A, $o2, $raiz)))->toBe(1452) // pai de outra obra
        ->and(Fx::errno(fn () => Fx::phase($this->B, $o1)))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::phase($this->A, $o1, null, ['planned_start' => '2026-02-01', 'planned_end' => '2026-01-01'])))->toBe(3819)
        ->and(Fx::errno(fn () => DB::table('project_phases')->where('id', $raiz)->delete()))->toBe(1451);
});

it('histórico de status: obra do mesmo tenant e estados válidos', function () {
    $obra = Fx::project($this->A, $this->coA, $this->user);
    $ins = fn (int $t, array $x) => DB::table('project_status_history')->insert(['tenant_id' => $t, 'project_id' => $obra, 'to_status' => 'in_progress', 'changed_by' => $this->user, ...$x]);

    expect(Fx::errno(fn () => $ins($this->A, ['from_status' => 'planning'])))->toBeNull()
        ->and(Fx::errno(fn () => $ins($this->B, [])))->toBe(1452)
        ->and(Fx::errno(fn () => $ins($this->A, ['to_status' => 'archived'])))->toBe(3819)
        ->and(Fx::errno(fn () => $ins($this->A, ['from_status' => 'x'])))->toBe(3819);
});

it('equipe da obra: funcionário e obra do mesmo tenant; um vínculo em aberto por funcionário; datas coerentes', function () {
    $obra = Fx::project($this->A, $this->coA, $this->user);
    $emp = Fx::employee($this->A, $this->coA);
    $empB = Fx::employee($this->B, $this->coB);

    expect(Fx::errno(fn () => Fx::projectMember($this->A, $obra, $emp)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::projectMember($this->A, $obra, $emp)))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::projectMember($this->A, $obra, $emp, ['ended_on' => '2026-02-01'])))->toBeNull() // histórico
        ->and(Fx::errno(fn () => Fx::projectMember($this->A, $obra, $emp, ['ended_on' => '2025-12-01'])))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::projectMember($this->A, $obra, $empB)))->toBe(1452)
        ->and(Fx::errno(fn () => DB::table('employees')->where('id', $emp)->delete()))->toBe(1451);
});

// ---------------------------------------------------------------- etapa 4: acesso por obra, por empresa, documentos
it('acesso por obra: usuário e quem concedeu são membros do tenant; obra do mesmo tenant; um nível por usuário e obra', function () {
    $obra = Fx::project($this->A, $this->coA, $this->user);
    $obraB = Fx::project($this->B, $this->coB, $this->user);
    $estranho = Fx::user();
    $ins = fn (int $t, int $u, int $p, array $x = []) => DB::table('user_project_access')->insert(['tenant_id' => $t, 'user_id' => $u, 'project_id' => $p, 'access_level' => 'view', ...$x]);

    expect(Fx::errno(fn () => $ins($this->A, $this->user, $obra, ['granted_by' => $this->user])))->toBeNull()
        ->and(Fx::errno(fn () => $ins($this->A, $this->user, $obra)))->toBe(1062)
        ->and(Fx::errno(fn () => $ins($this->A, $estranho, $obra)))->toBe(1452)
        ->and(Fx::errno(fn () => $ins($this->A, $this->user, $obraB)))->toBe(1452)
        ->and(Fx::errno(fn () => $ins($this->A, $this->user, Fx::project($this->A, $this->coA, $this->user, 'U-2'), ['granted_by' => $estranho])))->toBe(1452)
        ->and(Fx::errno(fn () => $ins($this->A, $this->user, Fx::project($this->A, $this->coA, $this->user, 'U-3'), ['access_level' => 'owner'])))->toBe(3819)
        ->and(Fx::errno(fn () => DB::table('projects')->where('id', $obra)->delete()))->toBe(1451);
});

it('usuário × empresa: membro do tenant, empresa do mesmo tenant, sem duplicidade', function () {
    $ins = fn (int $t, int $u, int $c, array $x = []) => DB::table('user_companies')->insert(['tenant_id' => $t, 'user_id' => $u, 'company_id' => $c, ...$x]);

    expect(Fx::errno(fn () => $ins($this->A, $this->user, $this->coA)))->toBeNull()
        ->and(Fx::errno(fn () => $ins($this->A, $this->user, $this->coA)))->toBe(1062)
        ->and(Fx::errno(fn () => $ins($this->A, $this->user, $this->coB)))->toBe(1452)
        ->and(Fx::errno(fn () => $ins($this->A, Fx::user(), $this->coA2)))->toBe(1452)
        ->and(Fx::errno(fn () => $ins($this->A, $this->user, $this->coA2, ['status' => 'blocked'])))->toBe(3819);
});

it('document_links aceita funcionário, equipamento e obra (D-P19) e continua recusando tipos fora da lista', function () {
    $doc = Fx::document($this->A, $this->user);
    $ins = fn (string $type) => DB::table('document_links')->insert(['tenant_id' => $this->A, 'document_id' => $doc, 'linkable_type' => $type, 'linkable_id' => 1]);

    expect(Fx::errno(fn () => $ins('employee')))->toBeNull()
        ->and(Fx::errno(fn () => $ins('equipment')))->toBeNull()
        ->and(Fx::errno(fn () => $ins('project')))->toBeNull()
        ->and(Fx::errno(fn () => $ins('company')))->toBeNull()
        ->and(Fx::errno(fn () => $ins('user')))->toBe(3819);
});
