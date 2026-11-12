<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function show(Request $request, TenantContext $context): JsonResponse
    {
        $t = $context->tenantOrFail();
        $user = $request->user();

        return response()->json(['data' => [
            'uuid' => $t->uuid, 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->status->value,
            'timezone' => $t->timezone, 'locale' => $t->locale,
            'roles' => $user->getRoleNames()->values()->all(),
        ]]);
    }
}
