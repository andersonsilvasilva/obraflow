<?php

namespace App\Modules\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddressRequest extends FormRequest
{
    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR',
        'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public function rules(): array
    {
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'kind' => ['sometimes', Rule::in(['main', 'billing', 'delivery', 'site'])],
            'street' => [$req, 'string', 'max:200'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'city' => [$req, 'string', 'max:100'],
            'state' => [$req, Rule::in(self::UFS)],
            'postal_code' => ['nullable', 'regex:/^\d{8}$/'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return ['postal_code.regex' => 'CEP deve ter 8 dígitos.', 'state.in' => 'UF inválida.'];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->filled('postal_code')) {
            $merge['postal_code'] = preg_replace('/\D+/', '', (string) $this->input('postal_code'));
        }
        if ($this->filled('state')) {
            $merge['state'] = strtoupper(trim((string) $this->input('state')));
        }
        $this->merge($merge);
    }
}
