<?php

namespace App\Modules\Contracts\Services;

use App\Models\Contract;
use App\Models\ContractAddendum;
use App\Models\ContractItem;
use App\Models\ContractStatusHistory;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida do contrato (FASE5_MODELO.md §5): rascunho → ativo (exige assinatura e itens; fixa o valor original)
 * → concluído; ativo ↔ suspenso (com motivo); rascunho/ativo/suspenso → cancelado (com motivo). Nada é excluído.
 * Cada passo: histórico + auditoria na mesma transação, com o contrato travado.
 */
final class ContractWorkflow
{
    /** ação => [estados de origem, destino] */
    private const STEPS = [
        'activate' => [['draft'], 'active'],
        'suspend' => [['active'], 'suspended'],
        'resume' => [['suspended'], 'active'],
        'complete' => [['active', 'suspended'], 'completed'],
        'cancel' => [['draft', 'active', 'suspended'], 'canceled'],
    ];

    public const ACTIONS = ['activate', 'suspend', 'resume', 'complete', 'cancel'];

    public function __construct(
        private readonly ContractService $contracts,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function run(Contract $contract, string $action, User $actor, ?string $reason = null, ?string $signedAt = null): Contract
    {
        [$from, $to] = self::STEPS[$action];

        return DB::transaction(function () use ($contract, $action, $from, $to, $actor, $reason, $signedAt) {
            $contract = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $old = $contract->status;

            if (! in_array($old, $from, true)) {
                throw ApiException::unprocessable('invalid_transition', "Não é possível {$this->label($action)} um contrato com status \"{$old}\".");
            }
            if (in_array($action, ['suspend', 'cancel'], true) && trim((string) $reason) === '') {
                throw ApiException::unprocessable('reason_required', 'Informe o motivo.');
            }
            if (in_array($action, ['complete', 'cancel'], true) && ContractAddendum::query()->where('contract_id', $contract->id)->where('status', 'draft')->exists()) {
                throw ApiException::unprocessable('pending_addendum', 'Há aditivo em rascunho: aprove ou cancele antes.');
            }

            if ($action === 'activate') {
                $signed = $signedAt ?? $contract->signed_at?->toDateString();
                if ($signed === null) {
                    throw ApiException::unprocessable('signature_required', 'Informe a data de assinatura para ativar o contrato.');
                }
                if (! ContractItem::query()->where('contract_id', $contract->id)->exists()) {
                    throw ApiException::unprocessable('empty_contract', 'Inclua ao menos um item antes de ativar o contrato.');
                }
                $this->contracts->recalculate($contract); // original = atual = Σ itens, ainda como rascunho
                $contract->forceFill(['signed_at' => $signed, 'current_end_date' => $contract->original_end_date]);
            }
            $contract->forceFill(['status' => $to])->save();

            $history = new ContractStatusHistory;
            $history->forceFill(['contract_id' => $contract->id, 'from_status' => $old, 'to_status' => $to,
                'changed_by' => $actor->getKey(), 'reason' => $reason])->save();

            $this->audit->record(AuditEvent::CONTRACT_STATUS_CHANGED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
                entityType: 'contract', entityId: $contract->id, old: ['status' => $old], new: ['status' => $to],
                metadata: array_filter(['action' => $action, 'reason' => $reason, 'original_value' => $action === 'activate' ? (string) $contract->original_value : null]));

            return $contract->refresh();
        });
    }

    private function label(string $action): string
    {
        return ['activate' => 'ativar', 'suspend' => 'suspender', 'resume' => 'retomar', 'complete' => 'concluir', 'cancel' => 'cancelar'][$action];
    }
}
