<?php

namespace App\Modules\Budgets\Services;

use App\Models\ReferenceComposition;
use App\Models\ReferenceDataset;
use App\Models\ReferenceResource;
use App\Modules\Budgets\Support\Dec;
use App\Modules\Shared\Exceptions\DomainRuleException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lê uma composição ou insumo de uma base de referência e devolve a CÓPIA para o orçamento (D-08):
 * dados do item, composição com itens diretos e a lista de insumos FINAIS com a árvore expandida
 * (coeficiente acumulado por unidade do item). Valores como publicados; nada é recalculado.
 */
final class ReferenceCopier
{
    private const MAX_DEPTH = 10;

    /**
     * @return array{item: array<string, mixed>, composition: ?array<string, mixed>, composition_items: list<array<string, mixed>>, resources: list<array<string, mixed>>}
     */
    public function copy(ReferenceDataset $dataset, string $type, string $code): array
    {
        $snapshot = [
            'source' => $dataset->source->code, 'dataset_id' => $dataset->id, 'competency' => $dataset->version,
            'state' => $dataset->state, 'regime' => $dataset->charges_regime, 'type' => $type, 'code' => $code,
        ];

        return $type === 'composition' ? $this->composition($dataset, $code, $snapshot) : $this->resource($dataset, $code, $snapshot);
    }

    private function composition(ReferenceDataset $dataset, string $code, array $snapshot): array
    {
        $c = ReferenceComposition::query()->where('dataset_id', $dataset->id)->where('code', $code)
            ->with(['unit', 'items.resource.unit', 'items.resource.price', 'items.child.unit'])->first()
            ?? throw new ModelNotFoundException;

        $items = [];
        foreach ($c->items as $i => $it) {
            $items[] = $it->resource_id
                ? ['item_type' => 'resource', 'kind' => $it->resource->kind, 'code' => $it->resource->code, 'description' => $it->resource->description,
                    'unit_code' => $it->resource->unit->code, 'coefficient' => (string) $it->coefficient, 'unit_price' => $it->unit_price,
                    'cost' => $it->cost, 'price_origin' => $it->price_origin, 'sort_order' => $i + 1]
                : ['item_type' => 'composition', 'kind' => null, 'code' => $it->child->code, 'description' => $it->child->description,
                    'unit_code' => $it->child->unit->code, 'coefficient' => (string) $it->coefficient, 'unit_price' => $it->unit_price,
                    'cost' => $it->cost, 'price_origin' => $it->price_origin, 'sort_order' => $i + 1];
        }

        $leaves = [];
        $this->expand($c, '1', $leaves, 0);

        return [
            'item' => ['code' => $c->code, 'description' => $c->description, 'unit_code' => $c->unit->code, 'unit_cost' => (string) $c->total_cost,
                'source' => 'official', 'source_ref' => $snapshot],
            'composition' => ['code' => $c->code, 'description' => $c->description, 'unit_code' => $c->unit->code, 'unit_cost' => (string) $c->total_cost,
                'source_snapshot' => $snapshot + ['class' => $c->class_code, 'price_origin' => $c->price_origin]],
            'composition_items' => $items,
            'resources' => array_values(array_map(fn ($l) => ['coefficient' => Dec::round($l['coefficient'], 7)] + $l, $leaves)),
        ];
    }

    private function resource(ReferenceDataset $dataset, string $code, array $snapshot): array
    {
        $r = ReferenceResource::query()->where('dataset_id', $dataset->id)->where('code', $code)->with(['unit', 'price'])->first()
            ?? throw new ModelNotFoundException;
        if ($r->price === null) {
            throw new DomainRuleException("O insumo {$code} não tem preço publicado nesta base.");
        }

        return [
            'item' => ['code' => $r->code, 'description' => $r->description, 'unit_code' => $r->unit->code, 'unit_cost' => (string) $r->price->price,
                'source' => 'official', 'source_ref' => $snapshot],
            'composition' => null,
            'composition_items' => [],
            'resources' => [['kind' => $r->kind, 'code' => $r->code, 'description' => $r->description, 'unit_code' => $r->unit->code,
                'coefficient' => '1.0000000', 'unit_price' => (string) $r->price->price]],
        ];
    }

    /** Expande a árvore somando coeficientes por insumo (coeficiente do caminho × coeficiente do item). */
    private function expand(ReferenceComposition $c, string $factor, array &$leaves, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new DomainRuleException("Composição {$c->code} com profundidade excessiva (possível ciclo na base).");
        }
        $c->loadMissing(['items.resource.unit', 'items.resource.price', 'items.child']);
        foreach ($c->items as $it) {
            $coef = Dec::mul($factor, (string) $it->coefficient);
            if ($it->resource_id) {
                $r = $it->resource;
                $leaves[$r->code] ??= ['kind' => $r->kind, 'code' => $r->code, 'description' => $r->description, 'unit_code' => $r->unit->code,
                    'coefficient' => '0', 'unit_price' => $r->price?->price !== null ? (string) $r->price->price : $it->unit_price];
                $leaves[$r->code]['coefficient'] = Dec::add($leaves[$r->code]['coefficient'], $coef);
            } else {
                $this->expand($it->child, $coef, $leaves, $depth + 1);
            }
        }
    }
}
