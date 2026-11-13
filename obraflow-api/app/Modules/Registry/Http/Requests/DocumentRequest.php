<?php

namespace App\Modules\Registry\Http\Requests;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /documents (multipart, arquivo opcional) e PUT /documents/{uuid}. */
class DocumentRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer', Rule::exists('document_categories', 'id')->where('tenant_id', $tenantId)],
            'status' => [$creating ? 'prohibited' : 'sometimes', Rule::in(['active', 'archived'])],
            'visibility' => ['sometimes', Rule::in(['private', 'restricted'])],
            'file' => [$creating ? 'sometimes' : 'prohibited', 'file', 'max:'.(int) config('obraflow.documents.max_kb')],
        ];
    }
}
