<?php

namespace App\Modules\Registry\Http\Requests;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitRequest extends FormRequest
{
    public function rules(): array
    {
        $req = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'code' => [$req, 'string', 'max:10', 'regex:/^[A-Za-z0-9²³%\/._-]+$/u',
                Rule::unique('units_of_measure', 'code')->where('tenant_id', $tenantId)
                    ->when($this->route('id'), fn ($r) => $r->whereNot('id', $this->route('id'))),
            ],
            'name' => [$req, 'string', 'max:60'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
