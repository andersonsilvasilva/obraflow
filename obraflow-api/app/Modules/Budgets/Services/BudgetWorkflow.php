<?php

namespace App\Modules\Budgets\Services;

use App\Models\Budget;
use App\Models\BudgetApproval;
use App\Models\BudgetStatusHistory;
use App\Models\BudgetVersion;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo da versão corrente (D-P34): rascunho → em revisão → aprovada | rejeitada; em revisão pode voltar a rascunho
 * (devolver); rejeitada pode ser reaberta (rascunho). Aprovar recalcula, bloqueia a versão (D-08), grava o
 * `totals_hash` e marca como `superseded` a aprovada anterior. Cada passo: histórico + auditoria na mesma transação,
 * com as linhas travadas (sem corrida).
 */
final class BudgetWorkflow
{
    /** ação => [de, para, evento] */
    private const STEPS = [
        'submit' => ['draft', 'in_review', AuditEvent::BUDGET_SUBMITTED],
        'withdraw' => ['in_review', 'draft', AuditEvent::BUDGET_WITHDRAWN],
        'approve' => ['in_review', 'approved', AuditEvent::BUDGET_APPROVED],
        'reject' => ['in_review', 'rejected', AuditEvent::BUDGET_REJECTED],
        'reopen' => ['rejected', 'draft', AuditEvent::BUDGET_REOPENED],
    ];

    public function __construct(
        private readonly BudgetCalculator $calculator,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function run(Budget $budget, string $action, User $actor, ?string $comment = null): BudgetVersion
    {
        [$from, $to, $event] = self::STEPS[$action];

        return DB::transaction(function () use ($budget, $action, $from, $to, $event, $actor, $comment) {
            $budget = Budget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            $version = BudgetVersion::query()->whereKey($budget->current_version_id)->lockForUpdate()->firstOrFail();

            if ($version->status !== $from) {
                throw ApiException::unprocessable('invalid_transition', "Não é possível {$this->label($action)} uma versão com status \"{$version->status}\".");
            }
            if ($action === 'reject' && trim((string) $comment) === '') {
                throw ApiException::unprocessable('comment_required', 'Informe o motivo da rejeição.');
            }
            if ($action === 'submit' && (int) ($version->totals_snapshot['item_count'] ?? 0) === 0) {
                throw ApiException::unprocessable('empty_budget', 'Adicione ao menos um item antes de enviar para revisão.');
            }

            $hash = null;
            if (in_array($action, ['approve', 'reject'], true)) {
                $hash = $this->totalsHash($this->calculator->recalculate($version));
            }

            if ($action === 'approve') {
                BudgetVersion::query()->where('budget_id', $budget->id)->where('status', 'approved')->whereKeyNot($version->id)
                    ->get()->each(fn (BudgetVersion $old) => $old->forceFill(['status' => 'superseded'])->save());
                $version->forceFill(['status' => 'approved', 'approved_at' => now(), 'locked_at' => now()])->save();
            } else {
                $version->forceFill(['status' => $to])->save();
            }
            $budget->forceFill(['status' => $to])->save();

            if ($hash !== null) {
                $approval = new BudgetApproval;
                $approval->forceFill(['budget_version_id' => $version->id, 'decision' => $to, 'decided_by' => $actor->getKey(),
                    'comment' => $comment, 'totals_hash' => $hash])->save();
            }
            $history = new BudgetStatusHistory;
            $history->forceFill(['budget_id' => $budget->id, 'budget_version_id' => $version->id, 'from_status' => $from,
                'to_status' => $to, 'changed_by' => $actor->getKey(), 'reason' => $comment])->save();

            $this->audit->record($event, tenantId: $this->context->idOrFail(), userId: $actor->getKey(), entityType: 'budget',
                entityId: $budget->id, old: ['status' => $from], new: ['status' => $to],
                metadata: array_filter(['version' => $version->version_number, 'comment' => $comment, 'totals_hash' => $hash,
                    'final_price' => $version->totals_snapshot['final_price'] ?? null]));

            return $version->refresh();
        });
    }

    /** SHA-256 dos totais em forma canônica (chaves ordenadas, sem o carimbo de cálculo). */
    public function totalsHash(array $snapshot): string
    {
        unset($snapshot['calculated_at']);
        $canon = function (array $a) use (&$canon): array {
            ksort($a);

            return array_map(fn ($v) => is_array($v) ? $canon($v) : (string) $v, $a);
        };

        return hash('sha256', json_encode($canon($snapshot), JSON_UNESCAPED_UNICODE));
    }

    private function label(string $action): string
    {
        return ['submit' => 'enviar para revisão', 'withdraw' => 'devolver', 'approve' => 'aprovar', 'reject' => 'rejeitar', 'reopen' => 'reabrir'][$action];
    }
}
