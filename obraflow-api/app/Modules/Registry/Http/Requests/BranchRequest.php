<?php

namespace App\Modules\Registry\Http\Requests;

use App\Modules\Registry\Rules\TaxDocument;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function rules(): array
    {
        $req = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->idOrFail();
        $ignore = $this->route('id');

        return [
            'name' => [$req, 'string', 'max:200'],
            'document_number' => ['nullable', 'string', 'max:20', new TaxDocument('cnpj'),
                Rule::unique('company_branches', 'document_number')->where('tenant_id', $tenantId)->whereNull('deleted_at')
                    ->when($ignore, fn ($r) => $r->whereNot('id', $ignore)),
            ],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('document_number')) {
            $this->merge(['document_number' => preg_replace('/\D+/', '', (string) $this->input('document_number'))]);
        }
    }
}
