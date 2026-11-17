<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Modules\Audit\AuditEvent;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Browser;
use Tests\Support\Scenario;

/*
 * obraflow:system-roles:sync — alinha papéis de sistema de tenants EXISTENTES ao catálogo, só acrescentando.
 * Cenário reproduz a HighTech: papel `member` criado antes da Fase 2 (4 permissões).
 */

const MEMBER_FASE1 = ['tenant.view', 'members.view', 'roles.view', 'settings.view'];
/** O que falta a um member anterior à Fase 2, na ordem do catálogo: leitura de cadastros (Fase 2) + equipamentos e obras (Fase 3, D-P28). */
const LEITURA_CADASTROS = ['units.view', 'companies.view', 'customers.view', 'suppliers.view', 'documents.view', 'equipment.view', 'projects.view', 'references.view', 'budgets.view', 'resources.view', 'contracts.view', 'schedules.view'];

function permsOf(Tenant $tenant, string $role): array
{
    return Role::query()->forTenant($tenant->id)->where('name', $role)->firstOrFail()
        ->permissions()->pluck('name')->sort()->values()->all();
}

beforeEach(function () {
    RateLimiter::clear('login');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
    foreach ([$this->A, $this->B] as $t) { // volta o member ao estado anterior à Fase 2
        app(TenantContext::class)->run($t, fn () => Role::query()->forTenant($t->id)->where('name', 'member')->firstOrFail()->syncPermissions(MEMBER_FASE1));
    }
});

it('--dry-run lista exatamente as permissões de leitura que faltam e não grava nada', function () {
    $antes = AuditLog::query()->count();

    Artisan::call('obraflow:system-roles:sync', ['slug' => $this->A->slug, '--role' => ['member'], '--dry-run' => true]);

    expect(Artisan::output())->toContain(implode(', ', LEITURA_CADASTROS))->toContain('Simulação')
        ->and(permsOf($this->A, 'member'))->toBe(collect(MEMBER_FASE1)->sort()->values()->all())
        ->and(AuditLog::query()->count())->toBe($antes);
});

it('concede só leitura (cadastros, equipamentos, obras) ao member, audita, e é idempotente', function () {
    Artisan::call('obraflow:system-roles:sync', ['slug' => $this->A->slug, '--role' => ['member'], '--yes' => true, '--no-interaction' => true]);

    $esperado = collect([...MEMBER_FASE1, ...LEITURA_CADASTROS])->sort()->values()->all();
    expect(permsOf($this->A, 'member'))->toBe($esperado);

    $log = AuditLog::query()->where('event', AuditEvent::PERMISSION_GRANTED)->latest('id')->firstOrFail();
    expect($log->tenant_id)->toBe($this->A->id)->and($log->new_values['permissions'])->toBe(LEITURA_CADASTROS);

    // segunda execução: nada muda, nenhum evento novo
    $eventos = AuditLog::query()->count();
    Artisan::call('obraflow:system-roles:sync', ['slug' => $this->A->slug, '--role' => ['member'], '--yes' => true, '--no-interaction' => true]);
    expect(Artisan::output())->toContain('Nada a fazer')
        ->and(AuditLog::query()->count())->toBe($eventos)
        ->and(permsOf($this->A, 'member'))->toBe($esperado);
});

it('não concede escrita, não mexe em outros papéis nem em outro tenant e nunca remove permissões', function () {
    // permissão extra dada manualmente ao member deve ser preservada (o comando só acrescenta)
    app(TenantContext::class)->run($this->A, fn () => Role::query()->forTenant($this->A->id)->where('name', 'member')->firstOrFail()->givePermissionTo('audit.view'));
    $adminAntes = permsOf($this->A, 'admin');
    $viewerAntes = permsOf($this->A, 'viewer');

    Artisan::call('obraflow:system-roles:sync', ['slug' => $this->A->slug, '--role' => ['member'], '--yes' => true, '--no-interaction' => true]);

    $member = permsOf($this->A, 'member');
    expect(array_filter($member, fn ($p) => str_ends_with($p, '.manage') || str_ends_with($p, '.invite') || str_ends_with($p, '.update')))->toBe([])
        ->and($member)->toContain('audit.view')
        ->and(permsOf($this->A, 'admin'))->toBe($adminAntes)
        ->and(permsOf($this->A, 'viewer'))->toBe($viewerAntes)
        ->and(permsOf($this->B, 'member'))->toBe(collect(MEMBER_FASE1)->sort()->values()->all());
});

it('recusa papel fora dos de sistema, papel ausente no comando e tenant inexistente', function () {
    expect(Artisan::call('obraflow:system-roles:sync', ['slug' => $this->A->slug, '--role' => ['gerente'], '--no-interaction' => true]))->toBe(1)
        ->and(Artisan::call('obraflow:system-roles:sync', ['slug' => $this->A->slug, '--no-interaction' => true]))->toBe(1)
        ->and(Artisan::call('obraflow:system-roles:sync', ['slug' => 'nao-existe', '--role' => ['member'], '--no-interaction' => true]))->toBe(1);
});

it('efeito prático: member passa a LER cadastros, mas continua sem escrever; isolamento preservado', function () {
    Artisan::call('obraflow:system-roles:sync', ['slug' => $this->A->slug, '--role' => ['member'], '--yes' => true, '--no-interaction' => true]);
    $user = Scenario::member($this->A, 'member');
    $b = new Browser($this);
    $b->login($user->email)->assertOk();

    foreach (['/api/v1/units', '/api/v1/companies', '/api/v1/customers', '/api/v1/suppliers', '/api/v1/documents'] as $uri) {
        $b->get($uri)->assertOk();
    }
    $b->post('/api/v1/companies', ['legal_name' => 'x', 'document_number' => '11222333000181'])->assertForbidden();
    $b->post('/api/v1/units', ['code' => 'm', 'name' => 'metro'])->assertForbidden();
    $b->post('/api/v1/documents', ['title' => 'x'])->assertForbidden();
    $b->get('/api/v1/audit-logs')->assertForbidden(); // outros módulos não foram ampliados

    // member de B (não sincronizado) continua sem acesso
    $userB = Scenario::member($this->B, 'member');
    $bb = new Browser($this);
    $bb->login($userB->email)->assertOk();
    $bb->get('/api/v1/companies')->assertForbidden();
});
