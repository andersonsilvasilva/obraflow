<?php

namespace App\Modules\Budgets\Services;

use App\Models\Budget;
use App\Models\BudgetBdi;
use App\Models\BudgetGroup;
use App\Models\BudgetIndirectCost;
use App\Models\BudgetItem;
use App\Models\BudgetVersion;
use App\Models\ReferenceDataset;
use App\Models\User;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Shared\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Escrita dos orçamentos (Fase 4B). Toda mudança: transação + auditoria (RegistryService) + recálculo
 * (BudgetCalculator) — os totais gravados nunca ficam desatualizados. Versão bloqueada recusa tudo (D-P31).
 */
final class BudgetService
{
    /** @var array<int, int> item da versão de origem → item copiado (usado ao copiar composições/BDI) */
    private array $copied = [];

    public function __construct(private readonly RegistryService $registry, private readonly BudgetCalculator $calculator) {}

    /** Cria o orçamento com a versão 1 (rascunho). Modo `reference`: UF e regime vêm da base escolhida (D-P03). */
    public function create(array $data, User $actor): Budget
    {
        return DB::transaction(function () use ($data, $actor) {
            if (($data['mode'] ?? null) === 'reference') {
                $ds = ReferenceDataset::query()->whereKey($data['reference_dataset_id'] ?? 0)->where('status', 'active')->first()
                    ?? throw new DomainRuleException('Escolha uma base de referência ativa.');
                $data['state'] = $ds->state;
                $data['social_charges_regime'] = $ds->charges_regime;
            } else {
                $data['reference_dataset_id'] = null;
                $data['social_charges_regime'] ??= 'not_applicable';
            }

            $budget = new Budget;
            $budget->owner_id = $actor->getKey();
            $this->registry->create($budget, $data, $actor);

            $version = new BudgetVersion(['label' => 'Versão 1']);
            $version->forceFill(['budget_id' => $budget->id, 'version_number' => 1, 'created_by' => $actor->getKey(),
                'social_charges_regime' => $budget->social_charges_regime]);
            $this->registry->create($version, [], $actor);

            $budget->forceFill(['current_version_id' => $version->id])->save();
            $this->calculator->recalculate($version);

            return $budget->setRelation('currentVersion', $version->refresh());
        });
    }

    public function update(Budget $budget, array $data, User $actor): Budget
    {
        unset($data['mode'], $data['reference_dataset_id'], $data['social_charges_regime'], $data['state']); // fixados na criação

        return $this->registry->update($budget, $data, $actor);
    }

    public function updateVersion(BudgetVersion $version, array $data, User $actor): array
    {
        return $this->mutate($version, fn () => $this->registry->update($version, $data, $actor));
    }

    // ------------------------------------------------------------ etapas

    public function addGroup(BudgetVersion $version, array $data, User $actor): BudgetGroup
    {
        $group = new BudgetGroup(['budget_version_id' => $version->id]);
        $this->mutate($version, fn () => $this->registry->create($group, $data + ['sort_order' => $this->nextOrder('budget_groups', $version->id)], $actor));

        return $group;
    }

    public function updateGroup(BudgetGroup $group, array $data, User $actor): BudgetGroup
    {
        $this->mutate($group->version, fn () => $this->registry->update($group, $data, $actor));

        return $group;
    }

    /** Etapa com subetapas ou itens → 409 `in_use` (FK RESTRICT). O BDI próprio da etapa sai junto. */
    public function deleteGroup(BudgetGroup $group, User $actor): void
    {
        $this->mutate($group->version, function () use ($group, $actor) {
            BudgetBdi::query()->where('group_id', $group->id)->get()->each(fn ($b) => $this->registry->delete($b, $actor));
            $this->registry->delete($group, $actor);
        });
    }

    // ------------------------------------------------------------ itens

    public function addItem(BudgetVersion $version, array $data, User $actor): BudgetItem
    {
        $item = new BudgetItem(['budget_version_id' => $version->id]);
        $this->mutate($version, fn () => $this->registry->create($item, $data + ['sort_order' => $this->nextOrder('budget_items', $version->id)], $actor));

        return $item->refresh();
    }

    public function updateItem(BudgetItem $item, array $data, User $actor): BudgetItem
    {
        $this->mutate($item->version, fn () => $this->registry->update($item, $data, $actor));

        return $item->refresh();
    }

    public function deleteItem(BudgetItem $item, User $actor): void
    {
        $this->mutate($item->version, function () use ($item, $actor) {
            BudgetBdi::query()->where('item_id', $item->id)->get()->each(fn ($b) => $this->registry->delete($b, $actor));
            $this->registry->delete($item, $actor);
        });
    }

    // ------------------------------------------------------------ BDI e custos indiretos (D-P02)

    /**
     * Define (ou troca) o BDI de um alvo: global, etapa ou item. `informed` usa o percentual enviado; `tcu` calcula
     * pela fórmula do TCU a partir dos componentes e guarda os componentes. `null` em $percent+$components remove.
     */
    public function setBdi(BudgetVersion $version, string $scope, ?int $targetId, string $method, ?string $percent, ?array $components, User $actor): ?BudgetBdi
    {
        $result = null;
        $this->mutate($version, function () use ($version, $scope, $targetId, $method, $percent, $components, $actor, &$result) {
            $existing = BudgetBdi::query()->where('budget_version_id', $version->id)->where('scope', $scope)
                ->when($scope === 'group', fn ($q) => $q->where('group_id', $targetId))
                ->when($scope === 'item', fn ($q) => $q->where('item_id', $targetId))->first();

            if ($percent === null && $components === null) {
                $existing && $this->registry->delete($existing, $actor);

                return;
            }

            $data = ['method' => $method, 'components' => $method === 'tcu' ? $components : null,
                'percent' => $method === 'tcu' ? BdiFormula::tcu($components ?? []) : $percent];
            $result = $existing
                ? $this->registry->update($existing, $data, $actor)
                : $this->registry->create(new BudgetBdi(['budget_version_id' => $version->id, 'scope' => $scope,
                    'group_id' => $scope === 'group' ? $targetId : null, 'item_id' => $scope === 'item' ? $targetId : null]), $data, $actor);
        });

        return $result;
    }

    public function addIndirect(BudgetVersion $version, array $data, User $actor): BudgetIndirectCost
    {
        $cost = new BudgetIndirectCost(['budget_version_id' => $version->id]);
        $this->mutate($version, fn () => $this->registry->create($cost, $this->indirectData($data), $actor));

        return $cost;
    }

    public function updateIndirect(BudgetIndirectCost $cost, array $data, User $actor): BudgetIndirectCost
    {
        $this->mutate($cost->version, fn () => $this->registry->update($cost, $this->indirectData($data + $cost->only(['method', 'percent', 'amount'])), $actor));

        return $cost;
    }

    public function deleteIndirect(BudgetIndirectCost $cost, User $actor): void
    {
        $this->mutate($cost->version, fn () => $this->registry->delete($cost, $actor));
    }

    /** Valor fixo zera o percentual e vice-versa (CHECK do banco). */
    private function indirectData(array $data): array
    {
        if (($data['method'] ?? null) === 'fixed') {
            $data['percent'] = null;
        } elseif (($data['method'] ?? null) === 'percent_of_direct') {
            $data['amount'] = null;
        }

        return $data;
    }

    // ------------------------------------------------------------ versões

    /**
     * Nova versão copiada da corrente (D-08): só quando a corrente está aprovada ou rejeitada (rascunho se edita).
     * Etapas (com a árvore remapeada) e itens são copiados; a nova versão nasce em rascunho e vira a corrente.
     */
    public function newVersion(Budget $budget, User $actor, ?string $label = null): BudgetVersion
    {
        return DB::transaction(function () use ($budget, $actor, $label) {
            $current = BudgetVersion::query()->whereKey($budget->current_version_id)->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, ['approved', 'rejected'], true)) {
                throw ApiException::conflict('version_not_closed', 'A versão atual ainda está em edição; altere-a em vez de criar outra.');
            }

            $number = (int) BudgetVersion::query()->where('budget_id', $budget->id)->max('version_number') + 1;
            $version = new BudgetVersion(['label' => $label ?? "Versão {$number}", 'discount_percent' => $current->discount_percent]);
            $version->forceFill(['budget_id' => $budget->id, 'version_number' => $number, 'created_by' => $actor->getKey(),
                'social_charges_regime' => $current->social_charges_regime]);
            $this->registry->create($version, [], $actor);

            $this->copyContents($current, $version);

            $budget->forceFill(['current_version_id' => $version->id, 'status' => 'draft'])->save();
            $this->calculator->recalculate($version);

            return $version->refresh();
        });
    }

    /** Copia etapas (remapeando pais), itens, BDI e custos indiretos. Composições copiadas entram na 4B.5. */
    private function copyContents(BudgetVersion $from, BudgetVersion $to): void
    {
        $map = [];
        $groups = BudgetGroup::query()->where('budget_version_id', $from->id)->get()->keyBy('id');
        $pending = $groups->all();
        while ($pending) { // pais antes dos filhos
            foreach ($pending as $id => $g) {
                if ($g->parent_id !== null && ! isset($map[$g->parent_id])) {
                    continue;
                }
                $copy = new BudgetGroup(['budget_version_id' => $to->id, 'parent_id' => $g->parent_id ? $map[$g->parent_id] : null,
                    'code' => $g->code, 'name' => $g->name, 'sort_order' => $g->sort_order]);
                $copy->save();
                $map[$id] = $copy->id;
                unset($pending[$id]);
            }
        }

        foreach (BudgetItem::query()->where('budget_version_id', $from->id)->get() as $item) {
            $copy = new BudgetItem(['budget_version_id' => $to->id, 'group_id' => $map[$item->group_id]] + $item->only([
                'code', 'description', 'unit_code', 'quantity', 'unit_cost', 'bdi_percent', 'discount_percent', 'source', 'source_ref', 'sort_order',
            ]));
            $copy->save();
            $this->copied[$item->id] = $copy->id;
        }

        foreach (BudgetBdi::query()->where('budget_version_id', $from->id)->get() as $bdi) {
            (new BudgetBdi(['budget_version_id' => $to->id, 'scope' => $bdi->scope,
                'group_id' => $bdi->group_id ? $map[$bdi->group_id] : null, 'item_id' => $bdi->item_id ? $this->copied[$bdi->item_id] : null,
                'method' => $bdi->method, 'components' => $bdi->components, 'percent' => $bdi->percent]))->save();
        }
        foreach (BudgetIndirectCost::query()->where('budget_version_id', $from->id)->get() as $cost) {
            (new BudgetIndirectCost(['budget_version_id' => $to->id] + $cost->only(['description', 'method', 'percent', 'amount', 'sort_order'])))->save();
        }
    }

    /** Executa a mudança na transação, conferindo o bloqueio, e recalcula. @return array<string, mixed> totais */
    private function mutate(BudgetVersion $version, callable $change): array
    {
        return DB::transaction(function () use ($version, $change) {
            BudgetVersion::query()->whereKey($version->id)->lockForUpdate()->first(); // serializa recálculos da versão
            $change();

            return $this->calculator->recalculate($version);
        });
    }

    private function nextOrder(string $table, int $versionId): int
    {
        return (int) DB::table($table)->where('budget_version_id', $versionId)->max('sort_order') + 1;
    }
}
