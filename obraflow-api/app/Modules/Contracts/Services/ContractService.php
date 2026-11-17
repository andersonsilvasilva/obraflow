<?php

namespace App\Modules\Contracts\Services;

use App\Models\Budget;
use App\Models\BudgetVersion;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Budgets\Support\Dec;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Shared\Exceptions\DomainRuleException;
use App\Modules\Shared\Support\YearlyCodeGenerator;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Escrita dos contratos (Fase 5A). Regras:
 * - contrato pertence a uma obra (A-45); a empresa é a da obra; cliente/fornecedor da mesma empresa (D-P46);
 * - código automático CTR-NNN/AAAA pelo ano do início (D-P49);
 * - rascunho: tudo editável; ativo/suspenso: só observações e regra de reajuste (itens e prazo mudam por aditivo,
 *   D-P48); concluído/cancelado: nada;
 * - total do item = arred2(quantidade × preço) (D-P32); em rascunho, original = atual = Σ itens.
 */
final class ContractService
{
    private const HEADER_AFTER_SIGNING = ['notes', 'readjustment_rule'];

    public function __construct(
        private readonly RegistryService $registry,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly YearlyCodeGenerator $codes,
    ) {}

    public function create(array $data, User $actor): Contract
    {
        return DB::transaction(function () use ($data, $actor) {
            $project = Project::query()->findOrFail($data['project_id']);
            $data['company_id'] = $project->company_id;
            $this->assertParty($data);
            if (blank($data['code'] ?? null)) {
                $data['code'] = $this->codes->next(Contract::class, 'CTR', YearlyCodeGenerator::yearOf($data['starts_at'] ?? null));
            }

            $contract = new Contract;
            $contract->forceFill(['created_by' => $actor->getKey(), 'current_end_date' => $data['original_end_date'] ?? null]);

            return $this->registry->create($contract, $data, $actor);
        });
    }

    public function update(Contract $contract, array $data, User $actor): Contract
    {
        unset($data['kind'], $data['project_id'], $data['company_id']); // fixados na criação
        if (in_array($contract->status, ['completed', 'canceled'], true)) {
            throw new DomainRuleException('Contrato concluído ou cancelado não pode ser alterado.');
        }
        if (! $contract->isDraft()) {
            $blocked = array_diff(array_keys($data), self::HEADER_AFTER_SIGNING);
            if ($blocked !== []) {
                throw new DomainRuleException('Contrato assinado: valores, itens e prazos só mudam por aditivo. Aqui só é possível alterar observações e regra de reajuste.');
            }
        }

        return DB::transaction(function () use ($contract, $data, $actor) {
            $merged = array_merge($contract->only(['kind', 'company_id', 'customer_id', 'supplier_id']), $data);
            $this->assertParty($merged);
            if ($contract->isDraft() && array_key_exists('original_end_date', $data)) {
                $contract->forceFill(['current_end_date' => $data['original_end_date']]);
            }

            return $this->registry->update($contract, $data, $actor);
        });
    }

    // ------------------------------------------------------------ itens (só em rascunho)

    public function addItem(Contract $contract, array $data, User $actor): ContractItem
    {
        $this->assertDraft($contract);

        return DB::transaction(function () use ($contract, $data, $actor) {
            $item = new ContractItem;
            $item->forceFill([
                'contract_id' => $contract->id,
                'original_quantity' => $data['quantity'],
                'quantity' => $data['quantity'],
                'total' => $this->total($data['quantity'], $data['unit_price']),
                'sort_order' => $data['sort_order'] ?? ((int) ContractItem::query()->where('contract_id', $contract->id)->max('sort_order') + 1),
            ]);
            unset($data['quantity']);
            $this->registry->create($item, $data, $actor);
            $this->recalculate($contract);

            return $item;
        });
    }

    public function updateItem(ContractItem $item, array $data, User $actor): ContractItem
    {
        $contract = $item->contract;
        $this->assertDraft($contract);

        return DB::transaction(function () use ($item, $contract, $data, $actor) {
            $quantity = $data['quantity'] ?? (string) $item->quantity;
            $price = $data['unit_price'] ?? (string) $item->unit_price;
            $item->forceFill(['original_quantity' => $quantity, 'quantity' => $quantity, 'total' => $this->total($quantity, $price)]);
            unset($data['quantity']);
            $this->registry->update($item, $data, $actor);
            $this->recalculate($contract);

            return $item;
        });
    }

    public function deleteItem(ContractItem $item, User $actor): void
    {
        $contract = $item->contract;
        $this->assertDraft($contract);
        DB::transaction(function () use ($item, $contract, $actor) {
            $this->registry->delete($item, $actor);
            $this->recalculate($contract);
        });
    }

    /**
     * Gera os itens a partir da versão APROVADA do orçamento (D-P47): preço de venda unitário (com BDI e descontos do
     * item; o desconto global é repartido proporcionalmente) arredondado a 2 casas; custos indiretos viram um item
     * "VB". Devolve a diferença (preço final do orçamento − total do contrato), mostrada na tela.
     *
     * @return array{items: int, contract_total: string, budget_final_price: string, difference: string}
     */
    public function generateFromBudget(Contract $contract, BudgetVersion $version, User $actor): array
    {
        $this->assertDraft($contract);
        $budget = Budget::query()->findOrFail($version->budget_id);
        if ($version->status !== 'approved' || ! $version->isLocked()) {
            throw new DomainRuleException('Use uma versão APROVADA do orçamento.');
        }
        if ((int) $budget->company_id !== (int) $contract->company_id || ($budget->project_id !== null && (int) $budget->project_id !== (int) $contract->project_id)) {
            throw new DomainRuleException('O orçamento deve ser da mesma empresa e da mesma obra do contrato.');
        }
        if (ContractItem::query()->where('contract_id', $contract->id)->exists()) {
            throw new DomainRuleException('O contrato já tem itens. Remova-os antes de gerar a partir do orçamento.');
        }

        return DB::transaction(function () use ($contract, $version, $budget, $actor) {
            $snapshot = $version->totals_snapshot ?? [];
            $factor = Dec::factor((string) ($snapshot['global_discount_percent'] ?? $version->discount_percent ?? '0'), minus: true);
            $items = DB::table('budget_items as i')
                ->join('budget_groups as g', 'g.id', '=', 'i.group_id')
                ->where('i.budget_version_id', $version->id)->where('i.quantity', '>', 0)
                ->orderBy('g.sort_order')->orderBy('g.id')->orderBy('i.sort_order')->orderBy('i.id')
                ->get(['i.id', 'i.code', 'i.description', 'i.unit_code', 'i.quantity', 'i.total_sale']);

            $now = now();
            $rows = [];
            $order = 0;
            foreach ($items as $i) {
                $price = Dec::round(Dec::div(Dec::mul((string) $i->total_sale, $factor), (string) $i->quantity));
                $rows[] = $this->row($contract, ++$order, $now, ['budget_item_id' => $i->id, 'code' => $i->code, 'description' => $i->description,
                    'unit_code' => $i->unit_code, 'quantity' => (string) $i->quantity, 'unit_price' => $price]);
            }
            $indirect = (string) ($snapshot['indirect_cost'] ?? '0');
            if (Dec::cmp($indirect, '0') > 0) {
                $rows[] = $this->row($contract, ++$order, $now, ['budget_item_id' => null, 'code' => 'CI', 'description' => 'Custos indiretos',
                    'unit_code' => 'VB', 'quantity' => '1', 'unit_price' => Dec::round(Dec::mul($indirect, $factor))]);
            }
            if ($rows === []) {
                throw new DomainRuleException('A versão aprovada não tem itens com quantidade.');
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('contract_items')->insert($chunk);
            }

            $contract->forceFill(['budget_version_id' => $version->id])->save();
            $this->recalculate($contract);
            $final = (string) ($snapshot['final_price'] ?? '0');
            $result = ['items' => count($rows), 'contract_total' => (string) $contract->current_value, 'budget_final_price' => Dec::round($final),
                'difference' => Dec::round(Dec::sub($final, (string) $contract->current_value))];

            $this->audit->record(AuditEvent::CONTRACT_ITEMS_GENERATED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
                entityType: 'contract', entityId: $contract->id, metadata: ['budget' => $budget->code, 'version' => $version->version_number] + $result);

            return $result;
        });
    }

    /** Recalcula os valores do contrato a partir dos itens: em rascunho, original = atual; depois, só o atual. */
    public function recalculate(Contract $contract): void
    {
        $sum = '0';
        foreach (ContractItem::query()->where('contract_id', $contract->id)->pluck('total') as $t) {
            $sum = Dec::add($sum, (string) $t);
        }
        $sum = Dec::round($sum);
        $contract->forceFill($contract->isDraft() ? ['original_value' => $sum, 'current_value' => $sum] : ['current_value' => $sum])->save();
    }

    public function total(string $quantity, string $price): string
    {
        return Dec::round(Dec::mul($quantity, $price));
    }

    private function row(Contract $contract, int $order, $now, array $data): array
    {
        return $data + [
            'tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'addendum_id' => null,
            'original_quantity' => $data['quantity'], 'total' => $this->total($data['quantity'], $data['unit_price']),
            'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function assertDraft(Contract $contract): void
    {
        if (! $contract->isDraft()) {
            throw new DomainRuleException('Os itens só mudam com o contrato em rascunho. Depois de ativado, use um aditivo.');
        }
    }

    /** Cliente (contrato com cliente) ou fornecedor (com fornecedor), da mesma empresa da obra. */
    private function assertParty(array $data): void
    {
        $kind = $data['kind'] ?? null;
        $customer = $data['customer_id'] ?? null;
        $supplier = $data['supplier_id'] ?? null;
        if ($kind === 'customer' && ($customer === null || $supplier !== null)) {
            throw new DomainRuleException('Contrato com cliente: informe o cliente (e não um fornecedor).');
        }
        if ($kind === 'supplier' && ($supplier === null || $customer !== null)) {
            throw new DomainRuleException('Contrato com fornecedor: informe o fornecedor (e não um cliente).');
        }
        $party = $kind === 'customer' ? Customer::query()->find($customer) : Supplier::query()->find($supplier);
        if ($party !== null && (int) $party->company_id !== (int) $data['company_id']) {
            throw new DomainRuleException('A parte do contrato deve ser da mesma empresa da obra.');
        }
    }
}
