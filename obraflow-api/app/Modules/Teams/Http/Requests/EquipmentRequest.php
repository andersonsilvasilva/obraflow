<?php

namespace App\Modules\Teams\Http\Requests;

use App\Models\Company;
use App\Models\Equipment;
use App\Models\Supplier;
use Illuminate\Validation\Rule;

/**
 * Equipamentos. Empresa só na criação; grupo do tipo `equipment`; unidade do tenant; fornecedor principal da
 * MESMA empresa (AGENTS.md §2: validar empresa em todos os relacionamentos). Código único entre os ativos da empresa.
 */
class EquipmentRequest extends TenantRequest
{
    protected array $uuidRefs = [
        'company_uuid' => [Company::class, 'company_id'],
        'main_supplier_uuid' => [Supplier::class, 'main_supplier_id'],
    ];

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $tenantId = $this->tenantId();
        $current = $this->route('uuid') ? Equipment::query()->where('uuid', $this->route('uuid'))->first() : null;
        $companyId = $current?->company_id ?? Company::query()->where('uuid', $this->input('company_uuid'))->value('id');

        return [
            'company_uuid' => [$creating ? 'required' : 'prohibited', 'uuid', $this->existsInTenant('companies')],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:30',
                Rule::unique('equipment', 'code')->where('tenant_id', $tenantId)->where('company_id', $companyId)
                    ->whereNull('deleted_at')->when($current, fn ($r) => $r->ignore($current->id))],
            'group_id' => ['nullable', 'integer', Rule::exists('resource_groups', 'id')->where('tenant_id', $tenantId)->where('kind', 'equipment')],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', 'string', 'max:60'],
            'condition' => ['nullable', 'string', 'max:60'],
            'unit_id' => ['nullable', 'integer', $this->existsInTenant('units_of_measure', 'id', false)],
            'main_supplier_uuid' => ['nullable', 'uuid',
                $this->existsInTenant('suppliers')->where('company_id', $companyId ?? 0)],
            'ownership' => ['sometimes', Rule::in(Equipment::OWNERSHIPS)],
            'status' => ['sometimes', Rule::in(Equipment::STATUSES)],
        ];
    }
}
