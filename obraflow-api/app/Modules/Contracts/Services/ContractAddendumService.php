<?php

namespace App\Modules\Contracts\Services;

use App\Models\Contract;
use App\Models\ContractAddendum;
use App\Models\ContractAddendumItem;
use App\Models\ContractItem;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Budgets\Support\Dec;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Shared\Exceptions\DomainRuleException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Aditivos (D-P48). Só contrato ATIVO recebe aditivo. Rascunho: editável (cabeçalho e linhas); aprovado: imutável.
 * - linhas `change` alteram a quantidade de um item (nunca abaixo de zero; na 5B, nunca abaixo do medido) sem mudar
 *   o preço (reajuste fica fora, D-P50); linhas `new` incluem itens;
 * - `value_delta` = Σ (total novo − total atual) dos itens afetados, para que atual = original + Σ aditivos seja exato;
 * - prazo: o aditivo com nova data passa a definir o término atual;
 * - acima de 25% de acréscimos sobre o valor original a resposta AVISA (Lei 14.133, contratos públicos), sem bloquear.
 */
final class ContractAddendumService
{
    public function __construct(
        private readonly RegistryService $registry,
        private readonly ContractService $contracts,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function create(Contract $contract, array $data, User $actor): ContractAddendum
    {
        return DB::transaction(function () use ($contract, $data, $actor) {
            $contract = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($contract->status !== 'active') {
                throw new DomainRuleException('Só contrato ativo recebe aditivo.');
            }
            $addendum = new ContractAddendum;
            $addendum->forceFill([
                'contract_id' => $contract->id, 'created_by' => $actor->getKey(),
                'number' => (int) ContractAddendum::query()->where('contract_id', $contract->id)->max('number') + 1,
            ]);

            return $this->registry->create($addendum, $data, $actor);
        });
    }

    public function update(ContractAddendum $addendum, array $data, User $actor): ContractAddendum
    {
        $this->assertDraft($addendum);

        return $this->registry->update($addendum, $data, $actor);
    }

    public function addLine(ContractAddendum $addendum, array $data, User $actor): ContractAddendumItem
    {
        $this->assertDraft($addendum);

        return DB::transaction(function () use ($addendum, $data, $actor) {
            $line = new ContractAddendumItem;
            $line->forceFill(['contract_id' => $addendum->contract_id, 'addendum_id' => $addendum->id]);
            $this->registry->create($line, $this->lineData($addendum, $data), $actor);
            $this->refreshDelta($addendum);

            return $line;
        });
    }

    public function updateLine(ContractAddendumItem $line, ContractAddendum $addendum, array $data, User $actor): ContractAddendumItem
    {
        $this->assertDraft($addendum);

        return DB::transaction(function () use ($line, $addendum, $data, $actor) {
            $this->registry->update($line, $this->lineData($addendum, $data + $line->only(['line_type', 'contract_item_id'])), $actor);
            $this->refreshDelta($addendum);

            return $line;
        });
    }

    public function deleteLine(ContractAddendumItem $line, ContractAddendum $addendum, User $actor): void
    {
        $this->assertDraft($addendum);
        DB::transaction(function () use ($line, $addendum, $actor) {
            $this->registry->delete($line, $actor);
            $this->refreshDelta($addendum);
        });
    }

    /** @return array{addendum: ContractAddendum, over_25_percent: bool} */
    public function approve(ContractAddendum $addendum, string $signedAt, User $actor): array
    {
        return DB::transaction(function () use ($addendum, $signedAt, $actor) {
            $contract = Contract::query()->whereKey($addendum->contract_id)->lockForUpdate()->firstOrFail();
            $addendum = ContractAddendum::query()->whereKey($addendum->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($addendum);
            if ($contract->status !== 'active') {
                throw new DomainRuleException('Só é possível aprovar aditivo de contrato ativo.');
            }
            $lines = ContractAddendumItem::query()->where('addendum_id', $addendum->id)->get();
            $this->assertKind($addendum, $lines->count());

            $delta = '0';
            foreach ($lines as $line) {
                if ($line->line_type === 'change') {
                    $item = ContractItem::query()->whereKey($line->contract_item_id)->lockForUpdate()->firstOrFail();
                    $quantity = Dec::add((string) $item->quantity, (string) $line->quantity_delta);
                    if (Dec::cmp($quantity, '0') < 0) {
                        throw new DomainRuleException("A quantidade do item \"{$item->description}\" ficaria negativa.");
                    }
                    $total = $this->contracts->total($quantity, (string) $item->unit_price);
                    $delta = Dec::add($delta, Dec::sub($total, (string) $item->total));
                    $item->forceFill(['quantity' => $quantity, 'total' => $total])->save();
                } else {
                    $item = new ContractItem(['code' => $line->code, 'description' => $line->description, 'unit_code' => $line->unit_code, 'unit_price' => (string) $line->unit_price]);
                    $total = $this->contracts->total((string) $line->quantity_delta, (string) $line->unit_price);
                    $item->forceFill(['contract_id' => $contract->id, 'addendum_id' => $addendum->id, 'original_quantity' => '0',
                        'quantity' => (string) $line->quantity_delta, 'total' => $total,
                        'sort_order' => (int) ContractItem::query()->where('contract_id', $contract->id)->max('sort_order') + 1])->save();
                    $line->forceFill(['contract_item_id' => $item->id])->save();
                    $delta = Dec::add($delta, $total);
                }
            }

            $this->contracts->recalculate($contract);
            if ($addendum->new_end_date !== null) {
                $contract->forceFill(['current_end_date' => $addendum->new_end_date])->save();
            }
            $addendum->forceFill(['status' => 'approved', 'signed_at' => $signedAt, 'value_delta' => Dec::round($delta),
                'approved_by' => $actor->getKey(), 'approved_at' => now()])->save();
            $over = $this->over25($contract);

            $this->audit->record(AuditEvent::CONTRACT_ADDENDUM_APPROVED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
                entityType: 'contract', entityId: $contract->id, metadata: array_filter([
                    'addendum' => $addendum->number, 'kind' => $addendum->kind, 'value_delta' => (string) $addendum->value_delta,
                    'new_end_date' => $addendum->new_end_date?->toDateString(), 'current_value' => (string) $contract->current_value, 'over_25_percent' => $over,
                ], fn ($v) => $v !== null));

            return ['addendum' => $addendum->refresh(), 'over_25_percent' => $over];
        });
    }

    public function cancel(ContractAddendum $addendum, User $actor, ?string $reason): ContractAddendum
    {
        $this->assertDraft($addendum);

        return DB::transaction(function () use ($addendum, $actor, $reason) {
            $addendum->forceFill(['status' => 'canceled'])->save();
            $this->audit->record(AuditEvent::CONTRACT_ADDENDUM_CANCELED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
                entityType: 'contract', entityId: $addendum->contract_id, metadata: array_filter(['addendum' => $addendum->number, 'reason' => $reason]));

            return $addendum;
        });
    }

    /** Σ acréscimos aprovados (deltas positivos) > 25% do valor original. */
    public function over25(Contract $contract, string $extra = '0'): bool
    {
        if (Dec::cmp((string) $contract->original_value, '0') <= 0) {
            return false;
        }
        $increase = Dec::cmp($extra, '0') > 0 ? $extra : '0';
        foreach (ContractAddendum::query()->where('contract_id', $contract->id)->where('status', 'approved')->pluck('value_delta') as $d) {
            if (Dec::cmp((string) $d, '0') > 0) {
                $increase = Dec::add($increase, (string) $d);
            }
        }

        return Dec::cmp($increase, Dec::mul((string) $contract->original_value, '0.25')) > 0;
    }

    /** Valor que o aditivo em rascunho somaria ao contrato (mesma conta da aprovação). */
    public function previewDelta(ContractAddendum $addendum): string
    {
        $delta = '0';
        foreach (ContractAddendumItem::query()->where('addendum_id', $addendum->id)->with('item')->get() as $line) {
            if ($line->line_type === 'change' && $line->item) {
                $quantity = Dec::add((string) $line->item->quantity, (string) $line->quantity_delta);
                $delta = Dec::add($delta, Dec::sub($this->contracts->total($quantity, (string) $line->item->unit_price), (string) $line->item->total));
            } elseif ($line->line_type === 'new') {
                $delta = Dec::add($delta, $this->contracts->total((string) $line->quantity_delta, (string) $line->unit_price));
            }
        }

        return Dec::round($delta);
    }

    private function refreshDelta(ContractAddendum $addendum): void
    {
        $addendum->forceFill(['value_delta' => $this->previewDelta($addendum)])->save();
    }

    private function lineData(ContractAddendum $addendum, array $data): array
    {
        if ($data['line_type'] === 'change') {
            $item = ContractItem::query()->where('contract_id', $addendum->contract_id)->whereKey($data['contract_item_id'] ?? 0)->first()
                ?? throw new DomainRuleException('Item não pertence a este contrato.');
            if (Dec::cmp(Dec::add((string) $item->quantity, (string) $data['quantity_delta']), '0') < 0) {
                throw new DomainRuleException("A quantidade do item \"{$item->description}\" ficaria negativa.");
            }

            return ['line_type' => 'change', 'contract_item_id' => $item->id, 'quantity_delta' => $data['quantity_delta'],
                'code' => null, 'description' => null, 'unit_code' => null, 'unit_price' => null];
        }

        return ['line_type' => 'new', 'contract_item_id' => null] + array_intersect_key($data, array_flip(['code', 'description', 'unit_code', 'unit_price', 'quantity_delta']));
    }

    private function assertKind(ContractAddendum $addendum, int $lines): void
    {
        $ok = match ($addendum->kind) {
            'term' => $addendum->new_end_date !== null && $lines === 0,
            'value', 'scope' => $lines > 0,
            default => $lines > 0 || $addendum->new_end_date !== null,
        };
        if (! $ok) {
            throw new DomainRuleException(match ($addendum->kind) {
                'term' => 'Aditivo de prazo: informe a nova data de término e não inclua itens.',
                'value', 'scope' => 'Inclua ao menos um item no aditivo.',
                default => 'Inclua itens ou uma nova data de término.',
            });
        }
    }

    private function assertDraft(ContractAddendum $addendum): void
    {
        if ($addendum->status !== 'draft') {
            throw new DomainRuleException('Aditivo aprovado ou cancelado não pode ser alterado.');
        }
    }
}
