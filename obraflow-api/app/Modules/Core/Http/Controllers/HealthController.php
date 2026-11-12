<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Health\HealthReport;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /** Liveness: o processo responde. Não toca em dependências. */
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'obraflow-api',
            'version' => 'v1',
            'time' => now()->utc()->toIso8601String(),
        ]);
    }

    /** Readiness: dependências críticas (MySQL, Redis). 503 se alguma falhar. */
    public function ready(HealthReport $report): JsonResponse
    {
        $result = $report->run();

        return response()->json([
            'status' => $result['healthy'] ? 'ok' : 'degraded',
            'checks' => $result['checks'],
            'time' => now()->utc()->toIso8601String(),
        ], $result['healthy'] ? 200 : 503);
    }
}
