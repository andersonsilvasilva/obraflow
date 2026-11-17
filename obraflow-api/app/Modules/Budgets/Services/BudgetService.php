<?php

namespace App\Modules\Budgets\Services;

use App\Models\Budget;
use App\Models\BudgetBdi;
use App\Models\BudgetComposition;
use App\Models\BudgetCompositionItem;
use App\Models\BudgetGroup;
use App\Models\BudgetIndirectCost;
use App\Models\BudgetItem;
use App\Models\BudgetItemResource;
use App\Models\BudgetVersion;
use App\Models\Project;
use App\Models\ReferenceDataset;
use App\Models\Resource;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Budgets\Exceptions\VersionLockedException;
use App\Modules\Budgets\Support\Dec;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Shared\Exceptions\DomainRuleException;
use App\Modules\Shared\Support\YearlyCodeGenerator;
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
            // encargo social da obra (A-47, D-P03): padrão do orçamento manual; a base escolhida deve ter o mesmo regime
            $projectRegime = ! empty($data['project_id']) ? Project::query()->whereKey($data['project_id'])->value('social_charges_regime') : null;
            if (($data['mode'] ?? null) === 'reference') {
                $ds = ReferenceDataset::query()->whereKey($data['reference_dataset_id'] ?? 0)->where('status', 'active')->first()
                    ?? throw new DomainRuleException('Escolha uma base de referência ativa.');
                if (in_array($projectRegime, ['with_relief', 'without_relief'], true) && $projectRegime !== $ds->charges_regime) {
                    throw new DomainRuleException('A obra usa '.($projectRegime === 'with_relief' ? 'COM' : 'SEM').' desoneração: escolha uma base com o mesmo regime de encargos.');
                }
                $data['state'] = $ds->state;
                $data['social_charges_regime'] = $ds->charges_regime;
            } else {
                $data['reference_dataset_id'] = null;
                $data['social_charges_regime'] ??= $projectRegime ?? 'not_applicable';
            }

            if (blank($data['code'] ?? null)) { // código automático com o ano da data-base
                $data['code'] = app(YearlyCodeGenerator::class)->next(Budget::class, 'ORC', YearlyCodeGenerator::yearOf($data['reference_date'] ?? null));
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

    /**
     * Item a partir de uma base de referência (composição ou insumo): cópia autossuficiente (D-08) com a
     * composição, seus itens diretos e os insumos finais. A base precisa ter o MESMO regime de encargos da versão
     * (D-P03) e, se o orçamento tiver UF, a mesma UF.
     */
    public function addReferenceItem(BudgetVersion $version, array $data, User $actor): BudgetItem
    {
        $dataset = ReferenceDataset::query()->whereKey($data['reference_dataset_id'])->whereIn('status', ['active', 'superseded'])->with('source')->firstOrFail();
        $budget = $version->budget;
        if ($version->social_charges_regime !== 'not_applicable' && $dataset->charges_regime !== $version->social_charges_regime) {
            throw new DomainRuleException('A base escolhida tem regime de encargos diferente do orçamento.');
        }
        if ($budget->state && $dataset->state !== $budget->state) {
            throw new DomainRuleException("A base escolhida é de {$dataset->state} e o orçamento é de {$budget->state}.");
        }

        $copy = app(ReferenceCopier::class)->copy($dataset, $data['type'], $data['code']);
        $item = new BudgetItem(['budget_version_id' => $version->id]);

        $this->mutate($version, function () use ($version, $data, $copy, $item, $actor) {
            $fields = $copy['item'] + ['group_id' => $data['group_id'], 'quantity' => $data['quantity'],
                'discount_percent' => $data['discount_percent'] ?? '0', 'bdi_percent' => $data['bdi_percent'] ?? null,
                'sort_order' => $this->nextOrder('budget_items', $version->id)];
            if (! empty($data['item_code'])) {
                $fields['code'] = $data['item_code'];
            }
            $this->registry->create($item, $fields, $actor);
            $this->storeCopy($version, $item, $copy['composition'], $copy['composition_items'], $copy['resources']);
        });

        return $item->refresh();
    }

    /**
     * Item a partir do catálogo da organização (D-P41): copia nome, unidade e preço vigentes com origem `catalog`;
     * mudar o preço no catálogo depois NÃO altera o orçamento (D-08). Insumo sem preço é recusado.
     */
    public function addCatalogItem(BudgetVersion $version, array $data, User $actor): BudgetItem
    {
        $resource = Resource::query()->where('uuid', $data['resource_uuid'])->where('status', 'active')->with('unit')->firstOrFail();
        if ($resource->price === null) {
            throw new DomainRuleException("O insumo \"{$resource->name}\" não tem preço no catálogo.");
        }
        $item = new BudgetItem(['budget_version_id' => $version->id]);

        $this->mutate($version, function () use ($version, $data, $resource, $item, $actor) {
            $this->registry->create($item, [
                'group_id' => $data['group_id'], 'code' => $data['item_code'] ?? ($resource->code ?: 'CAT'),
                'description' => $resource->name, 'unit_code' => $resource->unit->code, 'quantity' => $data['quantity'],
                'unit_cost' => (string) $resource->price, 'discount_percent' => $data['discount_percent'] ?? '0', 'bdi_percent' => $data['bdi_percent'] ?? null,
                'source' => 'catalog', 'sort_order' => $this->nextOrder('budget_items', $version->id),
                'source_ref' => ['source' => 'CATÁLOGO', 'type' => 'catalog', 'uuid' => $resource->uuid, 'code' => $resource->code ?? '',
                    'kind' => $resource->kind, 'price' => (string) $resource->price, 'price_date' => $resource->price_date?->toDateString(),
                    'competency' => $resource->price_date?->format('Y-m') ?? '', 'state' => '', 'regime' => ''],
            ], $actor);
            $this->storeCopy($version, $item, null, [], [['kind' => $resource->kind, 'code' => $resource->code ?: "CAT-{$resource->id}",
                'description' => $resource->name, 'unit_code' => $resource->unit->code, 'coefficient' => '1.0000000', 'unit_price' => (string) $resource->price]]);
        });

        return $item->refresh();
    }

    /** Preço de um item oficial alterado passa a `custom` (o valor publicado fica no `source_ref`). */
    public function updateItem(BudgetItem $item, array $data, User $actor): BudgetItem
    {
        if (isset($data['unit_cost']) && $item->source === 'official' && Dec::cmp((string) $data['unit_cost'], (string) $item->unit_cost) !== 0) {
            $data['source'] = 'custom';
        }
        $this->mutate($item->version, fn () => $this->registry->update($item, $data, $actor));

        return $item->refresh();
    }

    public function deleteItem(BudgetItem $item, User $actor): void
    {
        $this->mutate($item->version, function () use ($item, $actor) {
            BudgetBdi::query()->where('item_id', $item->id)->get()->each(fn ($b) => $this->registry->delete($b, $actor));
            $this->dropCopy($item);
            $this->registry->delete($item, $actor);
        });
    }

    /** Grava composição copiada, itens diretos e insumos finais de um item. */
    private function storeCopy(BudgetVersion $version, BudgetItem $item, ?array $composition, array $compositionItems, array $resources): void
    {
        if ($composition) {
            $comp = new BudgetComposition(['budget_version_id' => $version->id, 'budget_item_id' => $item->id] + $composition);
            $comp->save();
            foreach ($compositionItems as $ci) {
                (new BudgetCompositionItem(['budget_version_id' => $version->id, 'budget_composition_id' => $comp->id] + $ci))->save();
            }
        }
        foreach ($resources as $r) {
            (new BudgetItemResource(['budget_version_id' => $version->id, 'budget_item_id' => $item->id] + $r))->save();
        }
    }

    private function dropCopy(BudgetItem $item): void
    {
        $comp = BudgetComposition::query()->where('budget_item_id', $item->id)->first();
        if ($comp) {
            BudgetCompositionItem::query()->where('budget_composition_id', $comp->id)->get()->each->delete();
            $comp->delete();
        }
        BudgetItemResource::query()->where('budget_item_id', $item->id)->get()->each->delete();
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
            app(AuditLogger::class)->record(AuditEvent::BUDGET_VERSION_CREATED, tenantId: $budget->tenant_id, userId: $actor->getKey(),
                entityType: 'budget', entityId: $budget->id, metadata: ['from_version' => $current->version_number, 'version' => $number]);

            return $version->refresh();
        });
    }

    /** Copia etapas (remapeando pais), itens, BDI, custos indiretos, composições copiadas e insumos finais. */
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

        // composições copiadas e insumos finais acompanham o item
        foreach (BudgetComposition::query()->where('budget_version_id', $from->id)->with('items')->get() as $comp) {
            $this->storeCopy($to, BudgetItem::query()->findOrFail($this->copied[$comp->budget_item_id]),
                $comp->only(['code', 'description', 'unit_code', 'unit_cost', 'source_snapshot']),
                $comp->items->map(fn ($ci) => $ci->only(['item_type', 'kind', 'code', 'description', 'unit_code', 'coefficient', 'unit_price', 'cost', 'price_origin', 'sort_order']))->all(),
                []);
        }
        foreach (BudgetItemResource::query()->where('budget_version_id', $from->id)->get() as $r) {
            (new BudgetItemResource(['budget_version_id' => $to->id, 'budget_item_id' => $this->copied[$r->budget_item_id]]
                + $r->only(['kind', 'code', 'description', 'unit_code', 'coefficient', 'unit_price'])))->save();
        }
    }

    /** Executa a mudança na transação, conferindo o bloqueio, e recalcula. @return array<string, mixed> totais */
    private function mutate(BudgetVersion $version, callable $change): array
    {
        return DB::transaction(function () use ($version, $change) {
            $locked = BudgetVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail(); // serializa recálculos da versão
            if ($locked->locked_at !== null) {
                throw new VersionLockedException;
            }
            if ($locked->status !== 'draft') {
                throw new DomainRuleException('A versão está em revisão ou foi rejeitada: devolva/reabra para rascunho antes de editar.');
            }
            $change();

            return $this->calculator->recalculate($version);
        });
    }

    private function nextOrder(string $table, int $versionId): int
    {
        return (int) DB::table($table)->where('budget_version_id', $versionId)->max('sort_order') + 1;
    }
}
