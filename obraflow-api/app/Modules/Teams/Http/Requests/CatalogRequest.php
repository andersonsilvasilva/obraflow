<?php

namespace App\Modules\Teams\Http\Requests;

use Illuminate\Validation\Rule;

/** Cargos (/job-titles) e setores (/departments): nome único no tenant. A tabela vem do default `catalog` da rota. */
class CatalogRequest extends TenantRequest
{
    public function rules(): array
    {
        $table = $this->route('catalog');
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:100',
                Rule::unique($table, 'name')->where('tenant_id', $this->tenantId())
                    ->when($this->route('id'), fn ($r) => $r->ignore((int) $this->route('id')))],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
