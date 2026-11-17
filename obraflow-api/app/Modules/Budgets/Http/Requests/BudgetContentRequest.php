<?php

namespace App\Modules\Budgets\Http\Requests;

use App\Models\BudgetItem;
use App\Models\BudgetVersion;
use App\Modules\Budgets\Services\BdiFormula;
use App\Modules\Teams\Http\Requests\TenantRequest;
use Illuminate\Validation\Rule;

/**
 * Conteúdo de uma versão, conforme o nome da rota: versão, etapas, itens (manuais e de referência), BDI, custos
 * indiretos e decisões do fluxo. Etapas e itens precisam ser da MESMA versão (o banco também garante).
 * Precisão D-P32: quantidade até 4 casas; preços até 2; percentuais até 4.
 */
class BudgetContentRequest extends TenantRequest
{
    public function rules(): array
    {
        $name = (string) $this->route()?->getName();
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';
        $versionId = $this->versionId();
        $group = fn () => Rule::exists('budget_groups', 'id')->where('tenant_id', $this->tenantId())->where('budget_version_id', $versionId);
        $pct = ['numeric', 'min:0', 'max:999.9999', 'decimal:0,4'];

        return match (true) {
            str_ends_with($name, 'budgets.versions.update') => [
                'label' => ['sometimes', 'nullable', 'string', 'max:100'],
                'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:99.9999', 'decimal:0,4'],
            ],
            str_ends_with($name, 'budgets.versions.store') => ['label' => ['nullable', 'string', 'max:100']],
            str_contains($name, 'budgets.groups.') => [
                'code' => [$req, 'string', 'max:30'],
                'name' => [$req, 'string', 'max:200'],
                'parent_id' => ['nullable', 'integer', $group()],
                'sort_order' => ['sometimes', 'integer', 'min:0'],
            ],
            str_ends_with($name, 'budgets.items.reference') => [
                'reference_dataset_id' => ['required', 'integer'],
                'type' => ['required', Rule::in(['composition', 'resource'])],
                'code' => ['required', 'string', 'max:20'],
                'group_id' => ['required', 'integer', $group()],
                'quantity' => ['required', 'numeric', 'min:0', 'max:99999999999.9999', 'decimal:0,4'],
                'item_code' => ['nullable', 'string', 'max:30'],
                'bdi_percent' => ['nullable', ...$pct],
                'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:99.9999', 'decimal:0,4'],
            ],
            str_ends_with($name, 'budgets.items.catalog') => [
                'resource_uuid' => ['required', 'uuid', $this->existsInTenant('resources')],
                'group_id' => ['required', 'integer', $group()],
                'quantity' => ['required', 'numeric', 'min:0', 'max:99999999999.9999', 'decimal:0,4'],
                'item_code' => ['nullable', 'string', 'max:30'],
                'bdi_percent' => ['nullable', ...$pct],
                'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:99.9999', 'decimal:0,4'],
            ],
            str_contains($name, 'budgets.items.') => [
                'group_id' => [$req, 'integer', $group()],
                'code' => [$req, 'string', 'max:30'],
                'description' => [$req, 'string', 'max:5000'],
                'unit_code' => [$req, 'string', 'max:20'],
                'quantity' => [$req, 'numeric', 'min:0', 'max:99999999999.9999', 'decimal:0,4'],
                'unit_cost' => [$req, 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
                'bdi_percent' => ['nullable', ...$pct],
                'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:99.9999', 'decimal:0,4'],
                'sort_order' => ['sometimes', 'integer', 'min:0'],
            ],
            str_ends_with($name, 'budgets.bdi') => [
                'scope' => ['required', Rule::in(['global', 'group', 'item'])],
                'group_id' => ['required_if:scope,group', 'prohibited_unless:scope,group', 'nullable', 'integer', $group()],
                'item_id' => ['required_if:scope,item', 'prohibited_unless:scope,item', 'nullable', 'integer',
                    Rule::exists('budget_items', 'id')->where('tenant_id', $this->tenantId())->where('budget_version_id', $versionId)],
                'method' => ['required', Rule::in(['informed', 'tcu'])],
                'percent' => ['nullable', 'required_if:method,informed', ...$pct],
                'components' => ['nullable', 'required_if:method,tcu', 'array:'.implode(',', BdiFormula::COMPONENTS)],
                'components.*' => ['numeric', 'min:0', 'max:99.9999'],
                'remove' => ['sometimes', 'boolean'],
            ],
            str_contains($name, 'budgets.indirect.') => [
                'description' => [$req, 'string', 'max:200'],
                'method' => [$req, Rule::in(['fixed', 'percent_of_direct'])],
                'amount' => ['nullable', 'required_if:method,fixed', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
                'percent' => ['nullable', 'required_if:method,percent_of_direct', ...$pct],
            ],
            str_ends_with($name, 'budgets.workflow') => ['comment' => ['nullable', 'string', 'max:1000']],
            default => [],
        };
    }

    /** id da versão endereçada pela rota (orçamento por uuid + número da versão), no tenant ativo. */
    public function versionId(): int
    {
        return (int) BudgetVersion::query()->join('budgets', 'budgets.id', '=', 'budget_versions.budget_id')
            ->where('budgets.uuid', $this->route('uuid'))->where('budget_versions.version_number', (int) $this->route('number'))
            ->value('budget_versions.id');
    }

    public function item(): ?BudgetItem
    {
        return $this->route('id') ? BudgetItem::query()->where('budget_version_id', $this->versionId())->find((int) $this->route('id')) : null;
    }
}
