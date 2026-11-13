<?php

namespace App\Modules\Registry\Http\Requests;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        $req = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->idOrFail();
        $self = $this->route('id');

        return [
            'code' => [$req, 'string', 'max:30', 'regex:/^[a-z0-9_.-]+$/',
                Rule::unique('document_categories', 'code')->where('tenant_id', $tenantId)
                    ->when($self, fn ($r) => $r->whereNot('id', $self)),
            ],
            'name' => [$req, 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', Rule::exists('document_categories', 'id')->where('tenant_id', $tenantId),
                Rule::notIn(array_filter([(int) $self]))],
        ];
    }
}
