<?php

namespace App\Modules\Contracts\Http\Requests;

use App\Models\Budget;
use App\Models\ContractAddendum;
use App\Models\ContractAddendumItem;
use App\Modules\Teams\Http\Requests\TenantRequest;
use Illuminate\Validation\Rule;

/** Itens, fluxo, geração pelo orçamento e aditivos do contrato — regras pela rota (nome sem o prefixo v1.). */
class ContractContentRequest extends TenantRequest
{
    public function rules(): array
    {
        $qty = ['numeric', 'max:99999999999.9999', 'decimal:0,4'];
        $price = ['numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'];
        $creating = $this->isMethod('post');

        return match (preg_replace('/^v1\./', '', (string) $this->route()?->getName())) {
            'contracts.items.store', 'contracts.items.update' => [
                'code' => ['nullable', 'string', 'max:30'],
                'description' => [$creating ? 'required' : 'sometimes', 'string', 'max:2000'],
                'unit_code' => [$creating ? 'required' : 'sometimes', 'string', 'max:20'],
                'quantity' => [$creating ? 'required' : 'sometimes', ...$qty, 'gt:0'],
                'unit_price' => [$creating ? 'required' : 'sometimes', ...$price],
                'sort_order' => ['sometimes', 'integer', 'min:0'],
            ],
            'contracts.items.from-budget' => [
                'budget_uuid' => ['required', 'uuid', $this->existsInTenant((new Budget)->getTable())],
                'version' => ['required', 'integer', 'min:1'],
            ],
            'contracts.workflow' => [
                'reason' => ['nullable', 'string', 'max:1000'],
                'signed_at' => ['nullable', 'date_format:Y-m-d'],
            ],
            'contracts.addenda.store', 'contracts.addenda.update' => [
                'kind' => [$creating ? 'required' : 'sometimes', Rule::in(ContractAddendum::KINDS)],
                'description' => [$creating ? 'required' : 'sometimes', 'string', 'max:5000'],
                'new_end_date' => ['nullable', 'date_format:Y-m-d'],
            ],
            'contracts.addenda.approve' => ['signed_at' => ['required', 'date_format:Y-m-d']],
            'contracts.addenda.cancel' => ['reason' => ['nullable', 'string', 'max:1000']],
            'contracts.addenda.lines.store', 'contracts.addenda.lines.update' => [
                'line_type' => [$creating ? 'required' : 'prohibited', Rule::in(ContractAddendumItem::TYPES)],
                'contract_item_id' => ['exclude_unless:line_type,change', 'required', 'integer'],
                'quantity_delta' => [$creating ? 'required' : 'sometimes', ...$qty, 'not_in:0'],
                'code' => ['exclude_if:line_type,change', 'nullable', 'string', 'max:30'],
                'description' => ['exclude_if:line_type,change', $creating ? 'required_if:line_type,new' : 'sometimes', 'string', 'max:2000'],
                'unit_code' => ['exclude_if:line_type,change', $creating ? 'required_if:line_type,new' : 'sometimes', 'string', 'max:20'],
                'unit_price' => ['exclude_if:line_type,change', $creating ? 'required_if:line_type,new' : 'sometimes', ...$price],
            ],
            default => [],
        };
    }

    public function attributes(): array
    {
        return [
            'description' => 'descrição', 'unit_code' => 'unidade', 'quantity' => 'quantidade', 'unit_price' => 'preço unitário',
            'quantity_delta' => 'quantidade', 'kind' => 'tipo do aditivo', 'new_end_date' => 'novo término', 'signed_at' => 'assinatura',
            'budget_uuid' => 'orçamento', 'version' => 'versão',
        ];
    }
}
