<?php

namespace App\Modules\Budgets\Http\Requests;

use App\Models\Budget;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Project;
use App\Modules\Teams\Http\Requests\TenantRequest;
use Illuminate\Validation\Rule;

/**
 * POST /budgets e PUT /budgets/{uuid}. Empresa, modo e base só na criação (modo `reference` exige base ativa;
 * UF e regime vêm dela — D-P03). Obra e cliente da mesma empresa. Código único entre os ativos do tenant.
 */
class BudgetRequest extends TenantRequest
{
    protected array $uuidRefs = [
        'company_uuid' => [Company::class, 'company_id'],
        'project_uuid' => [Project::class, 'project_id'],
        'customer_uuid' => [Customer::class, 'customer_id'],
    ];

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $current = $this->route('uuid') ? Budget::query()->where('uuid', $this->route('uuid'))->first() : null;
        $companyId = $current?->company_id ?? Company::query()->where('uuid', $this->input('company_uuid'))->value('id') ?? 0;

        return [
            'company_uuid' => [$creating ? 'required' : 'prohibited', 'uuid', $this->existsInTenant('companies')],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:30',
                Rule::unique('budgets', 'code')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')->when($current, fn ($r) => $r->ignore($current->id))],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'mode' => [$creating ? 'required' : 'prohibited', Rule::in(Budget::MODES)],
            'reference_dataset_id' => [$creating ? Rule::requiredIf($this->input('mode') === 'reference') : 'prohibited', 'nullable', 'integer',
                Rule::exists('reference_datasets', 'id')->where('status', 'active')],
            'social_charges_regime' => [$creating ? 'sometimes' : 'prohibited', Rule::in(['with_relief', 'without_relief', 'not_applicable'])],
            'state' => ['nullable', 'string', 'size:2', 'alpha'],
            'project_uuid' => ['nullable', 'uuid', $this->existsInTenant('projects')->where('company_id', $companyId)],
            'customer_uuid' => ['nullable', 'uuid', $this->existsInTenant('customers')->where('company_id', $companyId)],
            'reference_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('state')) {
            $this->merge(['state' => strtoupper((string) $this->input('state'))]);
        }
    }
}
