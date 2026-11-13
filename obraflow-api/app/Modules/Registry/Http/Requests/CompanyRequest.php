<?php

namespace App\Modules\Registry\Http\Requests;

use App\Modules\Registry\Rules\TaxDocument;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /companies e PUT /companies/{uuid}. CNPJ único entre as empresas ATIVAS do tenant. */
class CompanyRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->idOrFail();
        $ignore = $this->route('uuid');

        return [
            'legal_name' => [$req, 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'document_number' => [$req, 'string', 'max:20', new TaxDocument('cnpj'),
                Rule::unique('companies', 'document_number')->where('tenant_id', $tenantId)->whereNull('deleted_at')
                    ->when($ignore, fn ($r) => $r->whereNot('uuid', $ignore)),
            ],
            'state_registration' => ['nullable', 'string', 'max:30'],
            'municipal_registration' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('document_number')) {
            $this->merge(['document_number' => preg_replace('/\D+/', '', (string) $this->input('document_number'))]);
        }
    }
}
