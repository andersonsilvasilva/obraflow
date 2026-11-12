<?php

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Access\Services\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // Expiração OBRIGATÓRIA e limitada (política D-20 / A11).
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:'.(int) config('obraflow.api_token_max_days')],
            // Menor privilégio (D-29): abilities OBRIGATÓRIAS, do catálogo, sem curinga e sem gestão de acesso.
            'abilities' => ['required', 'array', 'min:1', 'max:30'],
            'abilities.*' => ['string', 'distinct', Rule::in(PermissionCatalog::tokenAssignable())],
        ];
    }
}
