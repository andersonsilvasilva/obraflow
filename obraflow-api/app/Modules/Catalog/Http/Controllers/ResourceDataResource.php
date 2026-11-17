<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Models\Resource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Insumo do catálogo na API. @mixin Resource */
class ResourceDataResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'kind' => $this->kind,
            'code' => $this->code,
            'name' => $this->name,
            'brand' => $this->brand,
            'model' => $this->model,
            'price' => $this->price,
            'price_date' => $this->price_date?->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status,
            'unit' => $this->whenLoaded('unit', fn () => $this->unit?->only(['id', 'code', 'name'])),
            // grupo e subgrupo separados (como na listagem de referência): com pai, o vínculo é um subgrupo
            'group' => $this->whenLoaded('group', fn () => $this->group ? $this->group->only(['id', 'name', 'parent_id']) + ['parent_name' => $this->group->parent?->name] : null),
            'main_supplier' => $this->whenLoaded('mainSupplier', fn () => $this->mainSupplier?->only(['uuid', 'legal_name', 'trade_name'])),
            'job_title' => $this->whenLoaded('jobTitle', fn () => $this->jobTitle?->only(['id', 'name'])),
            'equipment' => $this->whenLoaded('equipment', fn () => $this->equipment?->only(['uuid', 'name', 'code'])),
            'labor_costs' => $this->whenLoaded('laborCosts', fn () => $this->laborCosts->map(fn ($c) => [
                'id' => $c->id, 'effective_date' => $c->effective_date?->toDateString(), 'hourly_cost' => $c->hourly_cost,
            ])),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
