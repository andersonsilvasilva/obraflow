<?php

namespace App\Modules\Core\Health;

class HealthReport
{
    /** @param iterable<HealthCheck> $checks */
    public function __construct(private readonly iterable $checks) {}

    /** @return array{healthy: bool, checks: array<string, string>} */
    public function run(): array
    {
        $results = [];
        $healthy = true;

        foreach ($this->checks as $check) {
            $ok = $check->passes();
            $results[$check->name()] = $ok ? 'ok' : 'fail';
            $healthy = $healthy && $ok;
        }

        return ['healthy' => $healthy, 'checks' => $results];
    }
}
