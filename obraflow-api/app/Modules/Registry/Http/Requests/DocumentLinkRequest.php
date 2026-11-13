<?php

namespace App\Modules\Registry\Http\Requests;

use App\Models\DocumentLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /documents/{uuid}/links. `linkable_key` = uuid (empresa, cliente, fornecedor) ou id (filial).
 * A existência no tenant ativo é conferida pelo controller/serviço (404 se não for do tenant).
 */
class DocumentLinkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'linkable_type' => ['required', Rule::in(DocumentLink::LINKABLE_TYPES)],
            'linkable_key' => ['required', 'string', 'max:36'],
        ];
    }
}
