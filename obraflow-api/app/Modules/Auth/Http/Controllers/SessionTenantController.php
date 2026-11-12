<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\SelectTenantRequest;
use App\Modules\Auth\Services\MePayload;
use App\Modules\Tenancy\TenantSelector;
use Illuminate\Http\JsonResponse;

class SessionTenantController extends Controller
{
    /** Seleção/troca explícita do tenant ativo (SPA). Audita tenant.selected (origem → destino). */
    public function store(SelectTenantRequest $request, TenantSelector $selector, MePayload $payload): JsonResponse
    {
        $selector->select($request, $request->user(), $request->validated('tenant_uuid'));

        return response()->json($payload->build($request, $request->user()));
    }
}
