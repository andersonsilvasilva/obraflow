<?php

namespace App\Modules\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /documents/{uuid}/versions — o tipo é conferido pelo CONTEÚDO no DocumentStorage. */
class DocumentVersionRequest extends FormRequest
{
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:'.(int) config('obraflow.documents.max_kb')]];
    }
}
