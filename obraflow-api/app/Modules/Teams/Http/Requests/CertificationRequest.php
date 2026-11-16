<?php

namespace App\Modules\Teams\Http\Requests;

use App\Models\Document;
use App\Models\EmployeeCertification;

/** Certificação do funcionário. Comprovante opcional: documento do tenant (acesso conferido no controller). */
class CertificationRequest extends TenantRequest
{
    protected array $uuidRefs = ['document_uuid' => [Document::class, 'document_id']];

    public function rules(): array
    {
        $current = $this->route('id') ? EmployeeCertification::query()->find((int) $this->route('id')) : null;

        return [
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:150'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => array_filter(['nullable', 'date', $this->notBefore('issued_at', $current?->issued_at)]),
            'document_uuid' => ['nullable', 'uuid', $this->existsInTenant('documents', 'uuid', false)],
        ];
    }
}
