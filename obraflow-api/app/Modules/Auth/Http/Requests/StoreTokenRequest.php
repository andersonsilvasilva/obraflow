<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'abilities' => ['sometimes', 'array'],
            'abilities.*' => ['string', 'max:100'],
        ];
    }
}
