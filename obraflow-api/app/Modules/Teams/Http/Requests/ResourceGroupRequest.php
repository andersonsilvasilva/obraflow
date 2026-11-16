<?php

namespace App\Modules\Teams\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Grupos de recursos. Nesta fase só `equipment` (materiais chegam na Fase 4). Pai do mesmo tenant e tipo;
 * nome único entre irmãos. Profundidade (2 níveis) é conferida pelo modelo (422 domain_rule).
 */
class ResourceGroupRequest extends TenantRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'kind' => [$creating ? 'sometimes' : 'prohibited', Rule::in(['equipment'])],
            'parent_id' => ['nullable', 'integer',
                Rule::exists('resource_groups', 'id')->where('tenant_id', $this->tenantId())->where('kind', 'equipment')],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
        ];
    }

    public function payload(): array
    {
        return $this->isMethod('post') ? ['kind' => 'equipment', ...$this->validated()] : $this->validated();
    }
}
