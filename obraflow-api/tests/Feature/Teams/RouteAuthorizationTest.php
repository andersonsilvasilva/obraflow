<?php

use Illuminate\Support\Facades\Route;

/*
 * Fase 3 — etapa 6: revisão estrutural. Quebra se uma rota nova de equipes/obras nascer sem tenant obrigatório
 * ou sem permissão explícita (em vez de depender de alguém lembrar de testar).
 */

function phase3Routes(): array
{
    $prefixes = ['v1.job-titles.', 'v1.departments.', 'v1.employees.', 'v1.resource-groups.', 'v1.equipment.', 'v1.projects.'];

    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => collect($prefixes)->contains(fn ($p) => str_starts_with((string) $r->getName(), $p)))
        ->values()->all();
}

it('toda rota da Fase 3 exige tenant ativo e uma permissão do catálogo (can:)', function () {
    $routes = phase3Routes();
    expect($routes)->toHaveCount(46);

    $semTenant = [];
    $semCan = [];
    foreach ($routes as $route) {
        $mw = $route->gatherMiddleware();
        if (! in_array('tenant.required', $mw, true)) {
            $semTenant[] = $route->getName();
        }
        if (! collect($mw)->contains(fn ($m) => is_string($m) && preg_match('/^can:(employees|equipment|projects)\.(view|manage)$/', $m))) {
            $semCan[] = $route->getName();
        }
    }

    expect($semTenant)->toBe([])->and($semCan)->toBe([]);
});

it('escrita fora das obras exige sempre *.manage (obras usam o nível por obra no controller)', function () {
    $fracas = collect(phase3Routes())
        ->filter(fn ($r) => ! str_starts_with((string) $r->getName(), 'v1.projects.'))
        ->filter(fn ($r) => array_intersect($r->methods(), ['POST', 'PUT', 'DELETE']) !== [])
        ->reject(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_ends_with($m, '.manage')))
        ->map(fn ($r) => $r->getName())->values()->all();

    expect($fracas)->toBe([]);
});

it('criar e excluir obra exigem projects.manage', function () {
    foreach (['v1.projects.store', 'v1.projects.destroy'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('can:projects.manage');
    }
});
