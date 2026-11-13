<?php

namespace App\Modules\Registry\Http\Requests;

use App\Models\DocumentPermission;
use App\Models\Role;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT /documents/{uuid}/permissions — substitui a lista. Sujeito `user` precisa ter vínculo ATIVO no tenant;
 * `role` precisa ser papel do tenant. Sujeito de outro tenant é recusado (422), nunca gravado.
 */
class DocumentPermissionsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array', 'max:200'],
            'permissions.*.subject_type' => ['required', Rule::in(DocumentPermission::SUBJECT_TYPES)],
            'permissions.*.subject_id' => ['required', 'integer', 'min:1'],
            'permissions.*.ability' => ['required', Rule::in(DocumentPermission::ABILITIES)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $tenantId = app(TenantContext::class)->idOrFail();

            foreach ($this->input('permissions', []) as $i => $entry) {
                $id = (int) $entry['subject_id'];
                $ok = $entry['subject_type'] === 'user'
                    ? DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $id)->where('membership_status', 'active')->exists()
                    : Role::query()->forTenant($tenantId)->whereKey($id)->exists();

                if (! $ok) {
                    $validator->errors()->add("permissions.{$i}.subject_id", 'Sujeito não encontrado neste tenant.');
                }
            }
        }];
    }
}
