<?php

namespace App\Modules\Planning\Services;

use App\Models\OwnComposition;
use App\Models\Project;
use App\Models\ReferenceComposition;
use App\Models\ReferenceDataset;
use App\Models\Resource;
use App\Models\Schedule;
use App\Models\ScheduleTask;
use App\Models\ScheduleTaskItem;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Budgets\Services\BdiFormula;
use App\Modules\Budgets\Services\ReferenceCopier;
use App\Modules\Budgets\Support\Dec;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Shared\Exceptions\DomainRuleException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recursos das atividades do planejamento (6B): lançados do catálogo (D-P90), do SINAPI (D-P89), de uma composição
 * própria (D-P87/D-P88), do orçamento (D-P93) ou digitados — sempre como CÓPIA do preço (D-08), com o custo unitário
 * separado por tipo. BDI e desconto do planejamento (D-P92). Só em atividade (`task`). Escritas auditadas (registry.*).
 */
final class ScheduleItemService
{
    public const KINDS = ScheduleTaskItem::COST_KINDS;

    public function __construct(
        private readonly RegistryService $registry,
        private readonly ReferenceCopier $copier,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    // ------------------------------------------------------------ BDI e desconto do planejamento

    /**
     * @param  array{bdi_method?: string, bdi_percent?: string|float|int|null, bdi_components?: array<string, mixed>|null, discount_percent?: string|float|int|null}  $data
     */
    public function setPricing(Schedule $schedule, array $data, User $actor): void
    {
        $changes = [];
        if (array_key_exists('bdi_percent', $data) || array_key_exists('bdi_components', $data)) {
            if (($data['bdi_method'] ?? 'informed') === 'tcu') {
                $components = array_intersect_key((array) ($data['bdi_components'] ?? []), array_flip(BdiFormula::COMPONENTS));
                $changes['bdi_percent'] = BdiFormula::tcu($components);
                $changes['bdi_components'] = array_map(fn ($v) => (string) $v, $components);
            } else {
                $changes['bdi_percent'] = (string) ($data['bdi_percent'] ?? '0');
                $changes['bdi_components'] = null;
            }
        }
        if (array_key_exists('discount_percent', $data)) {
            $changes['discount_percent'] = (string) ($data['discount_percent'] ?? '0');
        }
        if ($changes !== []) {
            $schedule->forceFill($changes);
            $this->registry->update($schedule, [], $actor);
        }
    }

    // ------------------------------------------------------------ lançar recursos

    /**
     * Lança recursos na atividade conforme a origem. Devolve os recursos criados.
     *
     * @param  array<string, mixed>  $data
     * @return list<ScheduleTaskItem>
     */
    public function add(Schedule $schedule, ScheduleTask $task, array $data, User $actor): array
    {
        $this->assertActivity($task);
        $qpu = (string) ($data['quantity_per_unit'] ?? '1');

        return DB::transaction(function () use ($schedule, $task, $data, $qpu, $actor) {
            $lines = match ($data['source']) {
                'catalog' => $this->fromCatalog((array) $data['resource_uuids']),
                'sinapi_composition', 'sinapi_resource' => [$this->fromSinapi($schedule, $data['source'], (int) $data['reference_dataset_id'], (string) $data['code'])],
                'own_composition' => [$this->fromOwn($schedule, (string) $data['own_composition_uuid'])],
                'manual' => [$this->manual($data)],
                default => throw new DomainRuleException('Origem de recurso inválida.'),
            };

            return array_map(fn (array $line) => $this->create($schedule, $task, $line + ['quantity_per_unit' => $qpu], $actor), $lines);
        });
    }

    /** @param array<string, mixed> $data consumo, BDI/desconto próprios; descrição, unidade e custo só nos digitados */
    public function update(ScheduleTaskItem $item, array $data, User $actor): ScheduleTaskItem
    {
        $changes = array_intersect_key($data, array_flip(['quantity_per_unit', 'bdi_percent', 'discount_percent']));
        if ($item->source === 'manual') {
            $changes += array_intersect_key($data, array_flip(['description', 'unit_code']));
            if (array_key_exists('unit_cost', $data)) {
                $changes += $this->split((string) $data['unit_cost'], [$item->kind === 'composition' ? 'other' : $item->kind => (string) $data['unit_cost']]);
            }
        } elseif (array_intersect_key($data, array_flip(['description', 'unit_code', 'unit_cost'])) !== []) {
            throw new DomainRuleException('O preço e a descrição deste recurso vêm da origem (cópia): para mudar, exclua e lance de novo. Só os recursos digitados são editáveis.');
        }
        $item->forceFill($changes);

        return $this->registry->update($item, [], $actor);
    }

    public function delete(ScheduleTaskItem $item, User $actor): void
    {
        $this->registry->delete($item, $actor);
    }

    /**
     * D-P93: traz os custos dos itens do orçamento para as atividades vinculadas que ainda não têm recursos.
     * Na criação a partir do orçamento, o BDI do planejamento começa igual ao global do orçamento.
     */
    public function fromBudget(Schedule $schedule, User $actor, bool $copyBdi = false): int
    {
        return DB::transaction(function () use ($schedule, $actor, $copyBdi) {
            $tasks = ScheduleTask::query()->where('schedule_id', $schedule->id)->where('kind', 'task')->whereNotNull('budget_item_id')
                ->whereNotIn('id', ScheduleTaskItem::query()->select('task_id')->where('schedule_id', $schedule->id))->get();
            $items = DB::table('budget_items')->whereIn('id', $tasks->pluck('budget_item_id'))->get()->keyBy('id');
            $kinds = [];
            foreach (DB::table('budget_item_resources')->whereIn('budget_item_id', $tasks->pluck('budget_item_id'))->get() as $r) {
                $k = in_array($r->kind, self::KINDS, true) ? $r->kind : 'other';
                $kinds[$r->budget_item_id][$k] = Dec::add($kinds[$r->budget_item_id][$k] ?? '0', Dec::mul((string) $r->coefficient, (string) ($r->unit_price ?? '0')));
            }
            $n = 0;
            foreach ($tasks as $task) {
                $bi = $items->get($task->budget_item_id);
                if (! $bi) {
                    continue;
                }
                $line = ['source' => 'budget', 'kind' => 'composition', 'code' => $bi->code, 'description' => Str::limit($bi->description, 500, ''),
                    'unit_code' => $bi->unit_code, 'source_ref' => ['budget_item_id' => $bi->id, 'budget_version_id' => $bi->budget_version_id, 'source' => $bi->source],
                    'quantity_per_unit' => '1'] + $this->split((string) $bi->unit_cost, $kinds[$bi->id] ?? []);
                $this->create($schedule, $task, $line, $actor);
                $n++;
            }
            if ($copyBdi && $schedule->budget_version_id) {
                $global = DB::table('budget_bdi')->where('budget_version_id', $schedule->budget_version_id)->where('scope', 'global')->first();
                if ($global) {
                    $schedule->forceFill(['bdi_percent' => (string) $global->percent, 'bdi_components' => $global->components ? json_decode($global->components, true) : null])->save();
                }
            }
            if ($n > 0) {
                $this->audit->record(AuditEvent::SCHEDULE_COSTS_IMPORTED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
                    entityType: 'schedule', entityId: $schedule->id, metadata: ['source' => 'budget', 'tasks' => $n]);
            }

            return $n;
        });
    }

    // ------------------------------------------------------------ origens

    /** @return list<array<string, mixed>> */
    private function fromCatalog(array $uuids): array
    {
        $resources = Resource::query()->whereIn('uuid', $uuids)->with('unit:id,code')->get()->keyBy('uuid');
        $out = [];
        foreach ($uuids as $uuid) {
            $r = $resources->get($uuid) ?? throw new DomainRuleException('Insumo do catálogo não encontrado.');
            if ($r->price === null) {
                throw new DomainRuleException("\"{$r->name}\" está sem preço no catálogo: informe o preço antes de lançar.");
            }
            $out[] = ['source' => 'catalog', 'kind' => $r->kind, 'code' => $r->code, 'description' => $r->name, 'unit_code' => $r->unit?->code ?? 'UN',
                'source_ref' => ['resource_uuid' => $r->uuid, 'price_date' => $r->price_date?->toDateString()]] + $this->split((string) $r->price, [$r->kind => (string) $r->price]);
        }

        return $out;
    }

    /** D-P89: base ativa com o MESMO regime de encargos da obra (D-P03). Composição = uma linha com o custo por tipo. */
    private function fromSinapi(Schedule $schedule, string $source, int $datasetId, string $code): array
    {
        $project = Project::query()->findOrFail($schedule->project_id);
        $dataset = ReferenceDataset::query()->with('source')->findOrFail($datasetId);
        if ($dataset->status !== 'active') {
            throw new DomainRuleException('A base de referência escolhida não está ativa.');
        }
        if (in_array($project->social_charges_regime, [null, 'not_applicable'], true)) {
            throw new DomainRuleException('A obra está "sem encargos": composições e insumos SINAPI não se aplicam. Use o catálogo ou uma composição própria.');
        }
        if ($dataset->charges_regime !== $project->social_charges_regime) {
            throw new DomainRuleException('A base escolhida tem outro regime de encargos que o da obra. Use uma base '.($project->social_charges_regime === 'with_relief' ? 'desonerada' : 'não desonerada').'.');
        }

        if ($source === 'sinapi_resource') {
            $copy = $this->copier->copy($dataset, 'resource', $code);
            $kind = $copy['resources'][0]['kind'] ?? null;
            $kind = in_array($kind, self::KINDS, true) ? $kind : 'other';

            return ['source' => 'sinapi_resource', 'kind' => $kind, 'code' => $copy['item']['code'], 'description' => Str::limit($copy['item']['description'], 500, ''),
                'unit_code' => $copy['item']['unit_code'], 'source_ref' => $copy['item']['source_ref']] + $this->split($copy['item']['unit_cost'], [$kind => $copy['item']['unit_cost']]);
        }

        $copy = $this->copier->copy($dataset, 'composition', $code);
        $c = ReferenceComposition::query()->where('dataset_id', $dataset->id)->where('code', $code)->firstOrFail();
        $parts = ['labor' => (string) ($c->labor_cost ?? '0'), 'material' => (string) ($c->material_cost ?? '0'), 'equipment' => (string) ($c->equipment_cost ?? '0'),
            'service' => (string) ($c->services_cost ?? '0'), 'other' => (string) ($c->other_cost ?? '0')];

        return ['source' => 'sinapi_composition', 'kind' => 'composition', 'code' => $copy['item']['code'], 'description' => Str::limit($copy['item']['description'], 500, ''),
            'unit_code' => $copy['item']['unit_code'], 'source_ref' => $copy['item']['source_ref'],
            'breakdown' => array_map(fn ($i) => array_intersect_key($i, array_flip(['item_type', 'kind', 'code', 'description', 'unit_code', 'coefficient', 'unit_price', 'cost'])), $copy['composition_items'])]
            + $this->split($copy['item']['unit_cost'], $parts);
    }

    /** D-P88: valores do regime de encargos da obra. */
    private function fromOwn(Schedule $schedule, string $uuid): array
    {
        $project = Project::query()->findOrFail($schedule->project_id);
        $c = OwnComposition::query()->where('uuid', $uuid)->with('unit:id,code')->first() ?? throw new DomainRuleException('Composição própria não encontrada.');
        if ($c->status !== 'active') {
            throw new DomainRuleException('A composição própria está inativa.');
        }
        $regime = $project->social_charges_regime ?? 'not_applicable';
        $costs = $c->costsFor($regime);
        $total = Dec::round(Dec::add('0', ...array_values($costs)));

        return ['source' => 'own_composition', 'kind' => 'composition', 'code' => $c->code, 'description' => Str::limit($c->name, 500, ''), 'unit_code' => $c->unit?->code ?? 'UN',
            'source_ref' => ['own_composition_uuid' => $c->uuid, 'reference_date' => $c->reference_date?->toDateString(), 'state' => $c->state, 'regime' => $regime]]
            + $this->split($total, $costs);
    }

    private function manual(array $data): array
    {
        $kind = (string) $data['kind'];

        return ['source' => 'manual', 'kind' => $kind, 'code' => $data['code'] ?? null, 'description' => (string) $data['description'], 'unit_code' => (string) $data['unit_code'],
            'source_ref' => null] + $this->split((string) $data['unit_cost'], [$kind => (string) $data['unit_cost']]);
    }

    // ------------------------------------------------------------ apoio

    /**
     * Custo unitário + parcelas por tipo que somam exatamente o total (CHECK do banco). Parcelas arredondadas; a
     * diferença vai para "outros" (ou sai da maior parcela, se a soma passar do total).
     *
     * @param  array<string, string>  $parts
     * @return array<string, string>
     */
    private function split(string $total, array $parts): array
    {
        $total = Dec::round($total);
        $out = [];
        foreach (self::KINDS as $k) {
            $out["cost_{$k}"] = Dec::round($parts[$k] ?? '0');
        }
        $diff = Dec::sub($total, Dec::add('0', ...array_values($out)));
        if (Dec::cmp($diff, '0') > 0) {
            $out['cost_other'] = Dec::round(Dec::add($out['cost_other'], $diff));
        } elseif (Dec::cmp($diff, '0') < 0) {
            arsort($out, SORT_NUMERIC);
            $key = array_key_first($out);
            $out[$key] = Dec::round(Dec::add($out[$key], $diff));
            if (Dec::cmp($out[$key], '0') < 0) { // não deveria acontecer; mantém a soma
                $out = array_fill_keys(array_map(fn ($k) => "cost_{$k}", self::KINDS), '0.00');
                $out['cost_other'] = $total;
            }
        }

        return ['unit_cost' => $total] + $out;
    }

    private function create(Schedule $schedule, ScheduleTask $task, array $line, User $actor): ScheduleTaskItem
    {
        $item = new ScheduleTaskItem;
        $item->forceFill($line + [
            'schedule_id' => $schedule->id,
            'task_id' => $task->id,
            'sort_order' => (int) ScheduleTaskItem::query()->where('task_id', $task->id)->max('sort_order') + 1,
        ]);

        return $this->registry->create($item, [], $actor);
    }

    private function assertActivity(ScheduleTask $task): void
    {
        if ($task->kind !== 'task') {
            throw new DomainRuleException($task->kind === 'summary'
                ? 'Recursos são lançados nas atividades; a etapa soma as filhas.'
                : 'Marco não tem recursos.');
        }
    }
}
