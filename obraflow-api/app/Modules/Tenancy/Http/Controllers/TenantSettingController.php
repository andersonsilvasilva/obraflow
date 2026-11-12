<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TenantSetting;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Configurações do tenant: dado de negócio protegido. Usa o modelo com BelongsToTenant — nenhuma consulta
 * escreve `tenant_id` à mão.
 */
class TenantSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = TenantSetting::query()->orderBy('setting_key')->get()
            ->map(fn (TenantSetting $s) => ['key' => $s->setting_key, 'value' => $s->setting_value]);

        return response()->json(['data' => $rows]);
    }

    public function upsert(Request $request, string $key, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        Validator::make(
            ['key' => $key, 'value' => $request->input('value')],
            ['key' => ['required', 'regex:/^[a-z0-9_.-]{1,100}$/'], 'value' => ['present']],
        )->validate();

        $setting = DB::transaction(function () use ($request, $key, $context, $audit): TenantSetting {
            $existing = TenantSetting::query()->where('setting_key', $key)->first();
            $old = $existing?->setting_value;

            $setting = $existing ?? new TenantSetting(['setting_key' => $key]);
            $setting->setting_value = ['value' => $request->input('value')];
            $setting->save();

            $audit->record(AuditEvent::TENANT_UPDATED, tenantId: $context->idOrFail(), userId: $request->user()->getKey(),
                entityType: 'tenant_setting', entityId: $setting->getKey(),
                old: $old === null ? null : ['key' => $key, 'value' => $old['value'] ?? null],
                new: ['key' => $key, 'value' => $request->input('value')]);

            return $setting;
        });

        return response()->json(['data' => ['key' => $setting->setting_key, 'value' => $setting->setting_value['value'] ?? null]]);
    }
}
