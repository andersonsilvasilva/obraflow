<?php

namespace App\Modules\Projects\Http\Requests;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Project;
use App\Modules\Registry\Http\Requests\AddressRequest;
use App\Modules\Teams\Http\Requests\TenantRequest;
use Illuminate\Validation\Rule;

/**
 * Obras. Empresa só na criação; cliente, responsável técnico e encarregado (A-46) da MESMA empresa (o banco também garante o
 * responsável; o cliente, o modelo). Código único entre as obras ativas do tenant. `status` NÃO é aceito aqui:
 * muda só por POST /projects/{uuid}/status (D-P27).
 */
class ProjectRequest extends TenantRequest
{
    protected array $uuidRefs = [
        'company_uuid' => [Company::class, 'company_id'],
        'customer_uuid' => [Customer::class, 'customer_id'],
        'technical_manager_uuid' => [Employee::class, 'technical_manager_id'],
        'foreman_uuid' => [Employee::class, 'foreman_id'],
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
            'foreman_uuid' => ['nullable', 'uuid', $this->existsInTenant('employees')->where('company_id', $companyId)],
            'contract_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'progress' => ['sometimes', 'integer', 'between:0,100'],
            // A-47: dados técnicos e endereço do canteiro no próprio formulário
            'built_area' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'duration_months' => ['nullable', 'integer', 'between:1,600'],
            'cno' => ['nullable', 'digits:12'],
            'social_charges_regime' => ['nullable', Rule::in(['with_relief', 'without_relief', 'not_applicable'])],
            'site_address' => ['nullable', 'array'],
            'site_address.postal_code' => ['nullable', 'digits:8'],
            'site_address.street' => ['nullable', 'string', 'max:200', 'required_with:site_address.city,site_address.state,site_address.number'],
            'site_address.number' => ['nullable', 'string', 'max:20'],
            'site_address.complement' => ['nullable', 'string', 'max:100'],
            'site_address.district' => ['nullable', 'string', 'max:100'],
            'site_address.city' => ['nullable', 'string', 'max:100', 'required_with:site_address.street'],
            'site_address.state' => ['nullable', Rule::in(AddressRequest::UFS), 'required_with:site_address.street'],
            'start_date' => ['nullable', 'date'],
            'expected_end_date' => array_filter(['nullable', 'date', $this->notBefore('start_date', $current?->start_date)]),
            'actual_end_date' => array_filter(['nullable', 'date', $this->notBefore('start_date', $current?->start_date)]),
            'status' => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return [
            'built_area' => 'área a ser construída', 'duration_months' => 'prazo (meses)', 'cno' => 'CNO', 'social_charges_regime' => 'encargo social',
            'site_address.postal_code' => 'CEP', 'site_address.street' => 'logradouro', 'site_address.city' => 'cidade', 'site_address.state' => 'UF',
        ];
    }

    /** CNO e CEP chegam com máscara da tela: grava só dígitos. */
    protected function prepareForValidation(): void
    {
        $this->digitsOnly('cno');
        if (is_array($this->input('site_address')) && filled($this->input('site_address.postal_code'))) {
            $this->merge(['site_address' => ['postal_code' => preg_replace('/\D+/', '', (string) $this->input('site_address.postal_code'))] + $this->input('site_address')]);
        }
    }

    public function messages(): array
    {
        return ['status.prohibited' => 'Use POST /projects/{uuid}/status para mudar o status.'];
    }
}
