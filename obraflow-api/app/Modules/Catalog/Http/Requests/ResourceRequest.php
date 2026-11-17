<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Models\Equipment;
use App\Models\Resource;
use App\Models\Supplier;
use App\Modules\Teams\Http\Requests\TenantRequest;
use Illuminate\Validation\Rule;

/**
 * Insumo do catálogo (D-P37). Tipo só na criação; grupo do mesmo tipo; cargo só para mão de obra e equipamento da
 * Fase 3 só para equipamento (o banco também garante). Código único entre os ativos do tenant.
 */
class ResourceRequest extends TenantRequest
{
    protected array $uuidRefs = [
        'main_supplier_uuid' => [Supplier::class, 'main_supplier_id'],
        'equipment_uuid' => [Equipment::class, 'equipment_id'],
    ];

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $current = $this->route('uuid') ? Resource::query()->where('uuid', $this->route('uuid'))->first() : null;
        $kind = $current?->kind ?? $this->input('kind');

        return [
            'kind' => [$creating ? 'required' : 'prohibited', Rule::in(Resource::KINDS)],
            'code' => ['nullable', 'string', 'max:30',
                Rule::unique('resources', 'code')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')->when($current, fn ($r) => $r->ignore($current->id))],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'unit_id' => [$creating ? 'required' : 'sometimes', 'integer', $this->existsInTenant('units_of_measure', 'id', false)],
            'group_id' => ['nullable', 'integer', Rule::exists('resource_groups', 'id')->where('tenant_id', $this->tenantId())->where('kind', (string) $kind)],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'main_supplier_uuid' => ['nullable', 'uuid', $this->existsInTenant('suppliers')],
            'job_title_id' => [$kind === 'labor' ? 'nullable' : 'prohibited', 'integer', $this->existsInTenant('job_titles', 'id', false)],
            'equipment_uuid' => [$kind === 'equipment' ? 'nullable' : 'prohibited', 'uuid', $this->existsInTenant('equipment')],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'price_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
