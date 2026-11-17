<?php

namespace App\Modules\Contracts\Http\Requests;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Supplier;
use App\Modules\Teams\Http\Requests\TenantRequest;
use Illuminate\Validation\Rule;

/**
 * POST /contracts e PUT /contracts/{uuid}. Obra e tipo só na criação (A-45, D-P45); a empresa é a da obra.
 * Cliente/fornecedor da mesma empresa da obra (regra repetida no ContractService e no CHECK do banco).
 * Código vazio na criação = automático CTR-NNN/AAAA (D-P49).
 */
class ContractRequest extends TenantRequest
{
    protected array $uuidRefs = [
        'project_uuid' => [Project::class, 'project_id'],
        'customer_uuid' => [Customer::class, 'customer_id'],
        'supplier_uuid' => [Supplier::class, 'supplier_id'],
    ];

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $current = $this->route('uuid') ? Contract::query()->where('uuid', $this->route('uuid'))->first() : null;
        $companyId = $current?->company_id ?? Project::query()->where('uuid', $this->input('project_uuid'))->value('company_id') ?? 0;
        $kind = $current?->kind ?? $this->input('kind');

        return [
            'project_uuid' => [$creating ? 'required' : 'prohibited', 'uuid', $this->existsInTenant('projects')],
            'kind' => [$creating ? 'required' : 'prohibited', Rule::in(Contract::KINDS)],
            'code' => [$creating ? 'nullable' : 'sometimes', 'string', 'max:30',
                Rule::unique('contracts', 'code')->where('tenant_id', $this->tenantId())->when($current, fn ($r) => $r->ignore($current->id))],
            'object' => [$creating ? 'required' : 'sometimes', 'string', 'max:5000'],
            'customer_uuid' => [$creating && $kind === 'customer' ? 'required' : 'sometimes', 'nullable', 'uuid', $kind === 'supplier' ? 'prohibited' : 'nullable',
                $this->existsInTenant('customers')->where('company_id', $companyId)],
            'supplier_uuid' => [$creating && $kind === 'supplier' ? 'required' : 'sometimes', 'nullable', 'uuid', $kind === 'customer' ? 'prohibited' : 'nullable',
                $this->existsInTenant('suppliers')->where('company_id', $companyId)],
            'starts_at' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'original_end_date' => array_filter(['nullable', 'date_format:Y-m-d', $this->notBefore('starts_at', $current?->starts_at)]),
            'signed_at' => ['nullable', 'date_format:Y-m-d'],
            'retention_percent' => ['sometimes', 'numeric', 'min:0', 'max:99.9999', 'decimal:0,4'],
            'readjustment_rule' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'project_uuid' => 'obra', 'kind' => 'tipo', 'code' => 'código', 'object' => 'objeto', 'customer_uuid' => 'cliente',
            'supplier_uuid' => 'fornecedor', 'starts_at' => 'início', 'original_end_date' => 'término', 'signed_at' => 'assinatura',
            'retention_percent' => 'retenção', 'readjustment_rule' => 'regra de reajuste', 'notes' => 'observações',
        ];
    }
}
