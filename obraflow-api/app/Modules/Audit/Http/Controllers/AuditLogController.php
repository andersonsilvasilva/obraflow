<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Consulta (somente leitura) da trilha do TENANT ATIVO. Eventos fora de tenant nunca aparecem aqui. */
class AuditLogController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $query = AuditLog::query()->where('tenant_id', $context->idOrFail())->orderByDesc('id');

        foreach (['event', 'entity_type', 'user_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        $page = $query->paginate(min((int) $request->integer('per_page', 50), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn (AuditLog $l) => [
                'uuid' => $l->uuid, 'event' => $l->event, 'user_id' => $l->user_id,
                'entity_type' => $l->entity_type, 'entity_id' => $l->entity_id,
                'old_values' => $l->old_values, 'new_values' => $l->new_values, 'metadata' => $l->metadata,
                'ip' => $l->ipText(), 'request_id' => $l->request_id, 'created_at' => $l->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
