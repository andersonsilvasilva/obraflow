<?php

namespace App\Modules\Projects\Http\Requests;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Project;
use App\Modules\Teams\Http\Requests\TenantRequest;
use Illuminate\Validation\Rule;

/**
 * Obras. Empresa só na criação; cliente e responsável técnico da MESMA empresa (o banco também garante o
 * responsável; o cliente, o modelo). Código único entre as obras ativas do tenant. `status` NÃO é aceito aqui:
 * muda só por POST /projects/{uuid}/status (D-P27).
 */
class ProjectRequest extends TenantRequest
{
    protected array $uuidRefs = [
        'company_uuid' => [Company::class, 'company_id'],
        'customer_uuid' => [Customer::class, 'customer_id'],
        'technical_manager_uuid' => [Employee::class, 'technical_manager_id'],
    ];

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $current = $this->route('uuid') ? Project::query()->where('uuid', $this->route('uuid'))->first() : null;
        $companyId = $current?->company_id ?? Company::query()->where('uuid', $this->input('company_uuid'))->value('id') ?? 0;

        return [
            'company_uuid' => [$creating ? 'required' : 'prohibited', 'uuid', $this->existsInTenant('companies')],
            'code' => [$creating ? 'nullable' : 'sometimes', 'string', 'max:30', // vazio na criação = automático OB-NNN/AAAA
                Rule::unique('projects', 'code')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')
                    ->when($current, fn ($r) => $r->ignore($current->id))],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'customer_uuid' => ['nullable', 'uuid', $this->existsInTenant('customers')->where('company_id', $companyId)],
            'technical_manager_uuid' => ['nullable', 'uuid', $this->existsInTenant('employees')->where('company_id', $companyId)],
            'contract_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'progress' => ['sometimes', 'integer', 'between:0,100'],
            'start_date' => ['nullable', 'date'],
            'expected_end_date' => array_filter(['nullable', 'date', $this->notBefore('start_date', $current?->start_date)]),
            'actual_end_date' => array_filter(['nullable', 'date', $this->notBefore('start_date', $current?->start_date)]),
            'status' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['status.prohibited' => 'Use POST /projects/{uuid}/status para mudar o status.'];
    }
}
