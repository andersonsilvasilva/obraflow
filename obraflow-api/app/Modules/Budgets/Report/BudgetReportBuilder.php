<?php

namespace App\Modules\Budgets\Report;

use App\Models\BudgetBdi;
use App\Models\BudgetComposition;
use App\Models\BudgetGroup;
use App\Models\BudgetIndirectCost;
use App\Models\BudgetItem;
use App\Models\BudgetVersion;
use App\Models\Project;
use App\Models\User;
use App\Modules\Budgets\Services\AbcCurve;
use App\Modules\Budgets\Services\BudgetCalculator;
use App\Modules\Budgets\Support\Dec;
use App\Modules\Shared\Exceptions\DomainRuleException;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Monta o ReportData único do relatório do orçamento (FASE4D_MODELO §3): os três formatos (PDF, XLSX, DOCX) só
 * formatam o que sai daqui, então não podem divergir. Valores pelo BudgetCalculator::compute (só leitura) e pela
 * AbcCurve — os mesmos da tela. Decimais sempre como string.
 */
final class BudgetReportBuilder
{
    public const STATUS = ['draft' => 'Rascunho', 'in_review' => 'Em revisão', 'approved' => 'Aprovado', 'rejected' => 'Reprovado', 'superseded' => 'Substituído'];

    public const REGIME = ['with_relief' => 'Desonerado', 'without_relief' => 'Não desonerado', 'not_applicable' => 'Sem encargos'];

    public const SOURCE = ['official' => 'SINAPI', 'custom' => 'SINAPI (alterado)', 'catalog' => 'Catálogo', 'manual' => 'Próprio'];

    public const KIND = ['material' => 'Material', 'labor' => 'Mão de obra', 'equipment' => 'Equipamento', 'service' => 'Serviço'];

    public const BDI_PARTS = ['ac' => 'Administração central (AC)', 's' => 'Seguro (S)', 'r' => 'Risco (R)', 'g' => 'Garantia (G)',
        'df' => 'Despesas financeiras (DF)', 'l' => 'Lucro (L)', 'i' => 'Tributos (I)'];

    public function __construct(private readonly BudgetCalculator $calculator, private readonly AbcCurve $abc) {}

    /** @return array<string, mixed> */
    public function build(BudgetVersion $version, ReportOptions $options, User $user): array
    {
        $budget = $version->budget()->with(['company', 'customer', 'dataset.source'])->firstOrFail();
        ['snapshot' => $totals, 'items' => $lines, 'models' => $items] = $this->calculator->compute($version);

        if ($options->has('analytic') && $items->count() > ReportOptions::MAX_ITEMS_WITH_ANALYTIC) {
            throw new DomainRuleException('O orçamento tem '.$items->count().' itens: acima de '.ReportOptions::MAX_ITEMS_WITH_ANALYTIC.', gere o relatório sem o analítico.');
        }

        $project = $budget->project_id ? Project::query()->with(['siteAddress', 'technicalManager'])->find($budget->project_id) : null;
        $groups = BudgetGroup::query()->where('budget_version_id', $version->id)->orderBy('sort_order')->orderBy('id')->get();
        $tz = app(TenantContext::class)->tenant()?->timezone ?: 'America/Sao_Paulo';
        $now = CarbonImmutable::now($tz);
        $abcResources = $this->abc->resources($version);

        $data = [
            'options' => ['format' => $options->format, 'parts' => $options->parts, 'hide_bdi' => $options->hideBdi],
            'meta' => [
                'code' => $budget->code,
                'title' => $budget->title,
                'version' => $version->version_number,
                'version_label' => $version->label,
                'status' => $version->status,
                'status_label' => self::STATUS[$version->status] ?? $version->status,
                'draft' => $version->status !== 'approved', // marca d'água "RASCUNHO" (D-P82)
                'approved_at' => $version->approved_at ? CarbonImmutable::parse($version->approved_at)->setTimezone($tz)->format('d/m/Y') : null,
                'reference_date' => $budget->reference_date ? CarbonImmutable::parse($budget->reference_date)->format('m/Y') : null,
                'regime' => self::REGIME[$version->social_charges_regime] ?? $version->social_charges_regime,
                'reference' => $this->reference($budget),
                'issued_at' => $now->format('d/m/Y H:i'),
                'issued_date' => $now->format('d/m/Y'),
                'issued_by' => $user->name,
                'filename' => $this->filename($budget->code, $version->version_number, $options->format),
            ],
            'company' => [
                'name' => $budget->company?->legal_name,
                'trade_name' => $budget->company?->trade_name,
                'document' => ReportFormat::cnpj($budget->company?->document_number),
            ],
            'customer' => $budget->customer ? ['name' => $budget->customer->name, 'document' => ReportFormat::cnpj($budget->customer->document_number)] : null,
            'project' => $project ? [
                'code' => $project->code,
                'name' => $project->name,
                'address' => $this->address($project),
                'technical_manager' => $project->technicalManager?->name,
            ] : null,
            'totals' => $totals,
            'summary' => $this->summary($groups, $totals, $abcResources),
            'rows' => $this->rows($groups, $items, $lines, $totals),
        ];

        if ($options->has('analytic')) {
            $data['analytic'] = $this->analytic($groups, $items, $lines);
        }
        if ($options->has('bdi')) {
            $data['bdi'] = $this->bdi($version, $groups, $items, $totals);
        }
        if ($options->has('abc')) {
            $data['abc'] = ['services' => $this->abc->services($version), 'resources' => $abcResources];
        }
        if ($options->has('closing')) {
            $data['closing'] = [
                'notes' => $options->notes,
                'place' => $options->place ?? $project?->siteAddress?->city,
                'date' => $now->format('d/m/Y'),
                'technician' => [
                    'name' => $options->technician['name'] ?? $project?->technicalManager?->name,
                    'registry' => $options->technician['registry'] ?? null,
                    'art' => $options->technician['art'] ?? null,
                ],
                'customer' => $budget->customer?->name,
            ];
        }

        return $data;
    }

    /** Nome do arquivo sem dados pessoais: "ORC-003-2026-v2-orcamento.pdf". */
    public function filename(string $code, int $version, string $format): string
    {
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $code), '-');

        return "{$slug}-v{$version}-orcamento.{$format}";
    }

    private function reference($budget): ?string
    {
        $ds = $budget->dataset;
        if (! $ds) {
            return null;
        }
        [$y, $m] = explode('-', $ds->version) + [1 => ''];

        return trim(($ds->source?->code ?? 'SINAPI')." {$ds->state} {$m}/{$y}").' · '.(self::REGIME[$ds->charges_regime] ?? $ds->charges_regime);
    }

    private function address(Project $project): ?string
    {
        $a = $project->siteAddress;
        if (! $a) {
            return null;
        }
        $line = trim($a->street.($a->number ? ", {$a->number}" : '').($a->complement ? " - {$a->complement}" : ''));
        $city = trim(($a->district ? "{$a->district}, " : '')."{$a->city}/{$a->state}");

        return "{$line} — {$city}";
    }

    /**
     * Totais por etapa raiz (% do preço dos itens) e distribuição dos insumos por tipo (das composições — Curva ABC).
     *
     * @return array<string, mixed>
     */
    private function summary($groups, array $totals, array $abcResources): array
    {
        $base = $totals['items_sale'];
        $stages = [];
        foreach ($groups->whereNull('parent_id') as $g) {
            $sale = $totals['groups'][$g->id]['sale'] ?? '0.00';
            $stages[] = ['code' => $g->code, 'name' => $g->name, 'cost' => $totals['groups'][$g->id]['cost'] ?? '0.00', 'sale' => $sale,
                'percent' => Dec::cmp($base, '0') === 0 ? '0.00' : Dec::round(Dec::mul(Dec::div($sale, $base), '100'), 2)];
        }

        $kinds = [];
        foreach ($abcResources as $r) {
            $k = $r['kind'] ?? 'other';
            $kinds[$k] = Dec::add($kinds[$k] ?? '0', $r['total']);
        }
        $kindTotal = Dec::add('0', ...array_values($kinds) ?: ['0']);
        $byKind = [];
        foreach (array_merge(array_keys(self::KIND), ['other']) as $k) {
            if (isset($kinds[$k])) {
                $byKind[] = ['kind' => $k, 'label' => self::KIND[$k] ?? 'Outros', 'total' => Dec::round($kinds[$k]),
                    'percent' => Dec::cmp($kindTotal, '0') === 0 ? '0.00' : Dec::round(Dec::mul(Dec::div($kinds[$k], $kindTotal), '100'), 2)];
            }
        }

        return ['stages' => $stages, 'by_kind' => $byKind, 'by_kind_total' => Dec::round($kindTotal)];
    }

    /**
     * Linhas do sintético na ordem da árvore: etapa (com subtotal) e, dentro dela, itens e subetapas.
     *
     * @return list<array<string, mixed>>
     */
    private function rows($groups, $items, array $lines, array $totals): array
    {
        $children = $groups->groupBy(fn ($g) => $g->parent_id ?? 0);
        $byGroup = $items->sortBy([['sort_order', 'asc'], ['id', 'asc']])->groupBy('group_id');
        $rows = [];
        $walk = function (int $parent, int $level) use (&$walk, &$rows, $children, $byGroup, $lines, $totals) {
            foreach ($children->get($parent, collect()) as $g) {
                $rows[] = ['type' => 'group', 'level' => $level, 'code' => $g->code, 'description' => $g->name,
                    'total_cost' => $totals['groups'][$g->id]['cost'] ?? '0.00', 'total_sale' => $totals['groups'][$g->id]['sale'] ?? '0.00'];
                foreach ($byGroup->get($g->id, collect()) as $item) {
                    $rows[] = $this->itemRow($item, $lines[$item->id], $level + 1);
                }
                $walk($g->id, $level + 1);
            }
        };
        $walk(0, 0);

        return $rows;
    }

    /** @return array<string, mixed> */
    private function itemRow(BudgetItem $item, array $line, int $level): array
    {
        // preço unitário com BDI (e desconto do item), para leitura; o total é o do cálculo (arredondado por item)
        $unitSale = Dec::round(Dec::mul(Dec::mul((string) $item->unit_cost, Dec::factor($line['bdi_percent'])), Dec::factor((string) $item->discount_percent, minus: true)));

        return ['type' => 'item', 'level' => $level, 'id' => $item->id, 'code' => $item->code, 'source' => self::SOURCE[$item->source] ?? $item->source,
            'reference_code' => $item->source_ref['code'] ?? null, 'description' => $item->description, 'unit' => $item->unit_code,
            'quantity' => (string) $item->quantity, 'unit_cost' => (string) $item->unit_cost, 'bdi_percent' => $line['bdi_percent'],
            'discount_percent' => (string) $item->discount_percent, 'unit_sale' => $unitSale,
            'total_cost' => $line['total_cost'], 'total_sale' => $line['total_sale']];
    }

    /**
     * Analítico: cada item com a composição copiada (insumos e composições auxiliares). Item sem composição: preço direto.
     *
     * @return list<array<string, mixed>>
     */
    private function analytic($groups, $items, array $lines): array
    {
        $comps = BudgetComposition::query()->whereIn('budget_item_id', $items->pluck('id'))->with('items')->get()->keyBy('budget_item_id');
        $out = [];
        foreach ($this->orderedItems($groups, $items) as $item) {
            $comp = $comps->get($item->id);
            $out[] = [
                'code' => $item->code, 'source' => self::SOURCE[$item->source] ?? $item->source, 'description' => $item->description,
                'unit' => $item->unit_code, 'quantity' => (string) $item->quantity, 'unit_cost' => (string) $item->unit_cost,
                'total_cost' => $lines[$item->id]['total_cost'],
                'composition' => $comp ? [
                    'code' => $comp->code, 'unit_cost' => (string) $comp->unit_cost,
                    'items' => $comp->items->sortBy('sort_order')->values()->map(fn ($ci) => [
                        'type' => $ci->item_type === 'composition' ? 'Composição' : (self::KIND[$ci->kind] ?? 'Insumo'),
                        'code' => $ci->code, 'description' => $ci->description, 'unit' => $ci->unit_code,
                        'coefficient' => (string) $ci->coefficient, 'unit_price' => $ci->unit_price !== null ? (string) $ci->unit_price : null,
                        'cost' => $ci->cost !== null ? (string) $ci->cost : null,
                    ])->all(),
                ] : null,
            ];
        }

        return $out;
    }

    /** Itens na mesma ordem do sintético. */
    private function orderedItems($groups, $items): array
    {
        $children = $groups->groupBy(fn ($g) => $g->parent_id ?? 0);
        $byGroup = $items->sortBy([['sort_order', 'asc'], ['id', 'asc']])->groupBy('group_id');
        $out = [];
        $walk = function (int $parent) use (&$walk, &$out, $children, $byGroup) {
            foreach ($children->get($parent, collect()) as $g) {
                foreach ($byGroup->get($g->id, collect()) as $item) {
                    $out[] = $item;
                }
                $walk($g->id);
            }
        };
        $walk(0);

        return $out;
    }

    /** @return array<string, mixed> */
    private function bdi(BudgetVersion $version, $groups, $items, array $totals): array
    {
        $rows = BudgetBdi::query()->where('budget_version_id', $version->id)->get();
        $describe = function (BudgetBdi $b): array {
            $components = [];
            if ($b->method === 'tcu') {
                foreach (self::BDI_PARTS as $key => $label) {
                    $components[] = ['key' => $key, 'label' => $label, 'percent' => (string) ($b->components[$key] ?? '0')];
                }
            }

            return ['method' => $b->method === 'tcu' ? 'Fórmula do TCU (Acórdão 2622/2013)' : 'Informado', 'percent' => (string) $b->percent, 'components' => $components];
        };
        $global = $rows->firstWhere('scope', 'global');
        $groupNames = $groups->keyBy('id');
        $itemNames = $items->keyBy('id');

        return [
            'global' => $global ? $describe($global) : null,
            'by_group' => $rows->where('scope', 'group')->map(fn ($b) => ['code' => $groupNames[$b->group_id]?->code, 'name' => $groupNames[$b->group_id]?->name] + $describe($b))->values()->all(),
            'by_item' => array_values(array_merge(
                $rows->where('scope', 'item')->map(fn ($b) => ['code' => $itemNames[$b->item_id]?->code, 'name' => $itemNames[$b->item_id]?->description] + $describe($b))->values()->all(),
                $items->whereNotNull('bdi_percent')->map(fn ($i) => ['code' => $i->code, 'name' => $i->description, 'method' => 'Informado no item', 'percent' => (string) $i->bdi_percent, 'components' => []])->values()->all(),
            )),
            'formula' => 'BDI = [(1 + AC + S + R + G) × (1 + DF) × (1 + L) ÷ (1 − I)] − 1',
            'indirect' => BudgetIndirectCost::query()->where('budget_version_id', $version->id)->orderBy('sort_order')->get()->map(fn ($c) => [
                'description' => $c->description,
                'basis' => $c->method === 'fixed' ? 'Valor fixo' : ReportFormat::percent((string) $c->percent).' do custo direto',
                'amount' => $c->method === 'fixed' ? (string) $c->amount : Dec::round(Dec::mul($totals['direct_cost'], Dec::div((string) $c->percent, '100'))),
            ])->all(),
            'regime' => self::REGIME[$version->social_charges_regime] ?? $version->social_charges_regime,
        ];
    }
}
