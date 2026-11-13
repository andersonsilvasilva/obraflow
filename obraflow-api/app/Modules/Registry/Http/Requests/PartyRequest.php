<?php

namespace App\Modules\Registry\Http\Requests;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Supplier;
use App\Modules\Registry\Rules\TaxDocument;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Clientes (/customers) e fornecedores (/suppliers). A empresa é informada por `company_uuid` e precisa ser
 * do tenant ativo e não excluída. Documento: CPF para `person`, CNPJ para `company`; único entre os ativos
 * da mesma empresa. Obrigatório só para fornecedor (D-30).
 */
class PartyRequest extends FormRequest
{
    public function isSupplier(): bool
    {
        return (bool) preg_match('/(^|\.)suppliers\./', (string) $this->route()?->getName()); // nomes têm prefixo "v1."
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';
        $table = $this->isSupplier() ? 'suppliers' : 'customers';
        $tenantId = app(TenantContext::class)->idOrFail();
        $ignore = $this->route('uuid');
        $current = $this->current();
        $type = $this->input('type', $current?->type);

        $rules = [
            'company_uuid' => [$creating ? 'required' : 'prohibited', 'uuid',
                Rule::exists('companies', 'uuid')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'type' => [$req, Rule::in(['person', 'company'])],
            'document_number' => [
                // Fornecedor exige documento (D-30); trocar o tipo exige reenviar o documento compatível.
                Rule::requiredIf(($this->isSupplier() && $creating)
                    || (! $creating && $this->has('type') && $this->input('type') !== $current?->type && $current?->document_number !== null)),
                $this->isSupplier() && ! $creating ? 'filled' : 'nullable', 'string', 'max:20',
                TaxDocument::forType($type),
                Rule::unique($table, 'document_number')->where('tenant_id', $tenantId)->whereNull('deleted_at')
                    ->where('company_id', $this->companyId())
                    ->when($ignore, fn ($r) => $r->whereNot('uuid', $ignore)),
            ],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];

        if ($this->isSupplier()) {
            $rules['legal_name'] = [$req, 'string', 'max:200'];
            $rules['trade_name'] = ['nullable', 'string', 'max:200'];
        } else {
            $rules['name'] = [$req, 'string', 'max:200'];
        }

        return $rules;
    }

    /** Registro em edição (escopo do tenant ativo); null na criação ou se não existir (o controller responde 404). */
    public function current(): Customer|Supplier|null
    {
        if (! $this->route('uuid')) {
            return null;
        }

        $model = $this->isSupplier() ? Supplier::class : Customer::class;

        return $this->currentParty ??= $model::query()->where('uuid', $this->route('uuid'))->first();
    }

    private Customer|Supplier|null $currentParty = null;

    /** id interno da empresa: do registro atual (edição) ou do corpo (criação). */
    private function companyId(): ?int
    {
        if ($current = $this->current()) {
            return $current->company_id;
        }

        return $this->filled('company_uuid')
            ? Company::query()->where('uuid', $this->input('company_uuid'))->value('id')
            : null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('document_number')) {
            $this->merge(['document_number' => preg_replace('/\D+/', '', (string) $this->input('document_number'))]);
        }
    }
}
