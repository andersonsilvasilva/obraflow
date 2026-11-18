<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Models\OwnComposition;
use App\Modules\Teams\Http\Requests\TenantRequest;
use Illuminate\Validation\Rule;

/**
 * Composição própria simplificada (6B, D-P87): nome, unidade, data de referência, UF e um valor por tipo
 * (equipamento e mão de obra conforme o regime de encargos — D-P88). Código único entre as ativas do tenant.
 */
class OwnCompositionRequest extends TenantRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $current = $this->route('uuid') ? OwnComposition::query()->where('uuid', $this->route('uuid'))->first() : null;
        $money = ['sometimes', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'];

        return [
            'code' => ['nullable', 'string', 'max:30',
                Rule::unique('own_compositions', 'code')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')->when($current, fn ($r) => $r->ignore($current->id))],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:250'],
            'unit_id' => [$creating ? 'required' : 'sometimes', 'integer', $this->existsInTenant('units_of_measure', 'id', false)],
            'reference_date' => [$creating ? 'required' : 'sometimes', 'date'],
            'state' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            ...array_fill_keys(OwnComposition::VALUES, $money),
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public function attributes(): array
    {
        return [
            'material_value' => 'materiais', 'service_value' => 'serviços', 'other_value' => 'outros',
            'equipment_value_none' => 'equipamentos (sem encargos)', 'equipment_value_relief' => 'equipamentos (desonerado)', 'equipment_value_no_relief' => 'equipamentos (não desonerado)',
            'labor_value_none' => 'cargo/função (sem encargos)', 'labor_value_relief' => 'cargo/função (desonerado)', 'labor_value_no_relief' => 'cargo/função (não desonerado)',
        ];
    }
}
