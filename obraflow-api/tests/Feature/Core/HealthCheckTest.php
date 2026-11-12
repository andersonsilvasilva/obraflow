<?php

use App\Modules\Core\Health\HealthCheck;
use App\Modules\Core\Health\HealthReport;

function fakeCheck(string $name, bool $passes): HealthCheck
{
    return new class($name, $passes) implements HealthCheck
    {
        public function __construct(private string $n, private bool $ok) {}

        public function name(): string
        {
            return $this->n;
        }

        public function passes(): bool
        {
            return $this->ok;
        }
    };
}

it('responde liveness em /api/v1/health sem depender de servicos', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('service', 'obraflow-api')
        ->assertHeader('X-Request-Id');
});

it('retorna 200 no readiness quando todas as dependencias estao ok', function () {
    $this->app->bind(HealthReport::class, fn () => new HealthReport([
        fakeCheck('database', true),
        fakeCheck('redis', true),
    ]));

    $this->getJson('/api/v1/health/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database', 'ok')
        ->assertJsonPath('checks.redis', 'ok');
});

it('retorna 503 no readiness quando uma dependencia falha', function () {
    $this->app->bind(HealthReport::class, fn () => new HealthReport([
        fakeCheck('database', true),
        fakeCheck('redis', false),
    ]));

    $this->getJson('/api/v1/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.redis', 'fail');
});
