<?php

namespace App\Modules\Teams\Http\Resources;

use App\Models\Equipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Equipment */
class EquipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'brand' => $this->brand,
            'model' => $this->model,
            'kind' => $this->kind,
            'condition' => $this->condition,
            'ownership' => $this->ownership,
            'status' => $this->status,
            'company' => $this->whenLoaded('company', fn () => ['uuid' => $this->company->uuid, 'legal_name' => $this->company->legal_name]),
            'group' => $this->whenLoaded('group', fn () => $this->group ? [
                'id' => $this->group->id, 'name' => $this->group->name, 'parent_id' => $this->group->parent_id,
            ] : null),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit?->only(['id', 'code', 'name'])),
            'main_supplier' => $this->whenLoaded('mainSupplier', fn () => $this->mainSupplier?->only(['uuid', 'legal_name', 'trade_name'])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
