<?php

namespace App\Modules\Teams\Http\Requests;

use App\Models\ResourceGroup;
use App\Modules\Teams\Http\Controllers\ResourceGroupController;
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
        $kinds = ResourceGroupController::kindsOf($this);
        $kind = $this->kind();

        return [
            'kind' => [$creating ? (count($kinds) > 1 ? 'required' : 'sometimes') : 'prohibited', Rule::in($kinds)],
            'parent_id' => ['nullable', 'integer',
                Rule::exists('resource_groups', 'id')->where('tenant_id', $this->tenantId())->where('kind', (string) $kind)],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
        ];
    }

    /** Tipo do grupo: o enviado (criação, catálogo), o do grupo editado, ou equipment. */
    private function kind(): ?string
    {
        if ($this->route('id')) {
            return ResourceGroup::query()->whereKey((int) $this->route('id'))->value('kind');
        }

        return $this->input('kind', ResourceGroupController::kindsOf($this)[0]);
    }

    public function payload(): array
    {
        return $this->isMethod('post') ? ['kind' => $this->kind(), ...$this->validated()] : $this->validated();
    }
}
