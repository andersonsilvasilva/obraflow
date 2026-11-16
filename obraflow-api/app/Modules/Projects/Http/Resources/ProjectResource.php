<?php

namespace App\Modules\Projects\Http\Resources;

use App\Models\Project;
use App\Modules\Registry\Http\Resources\AddressResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Obra. `access_level` = nível efetivo do usuário que consulta (o frontend habilita/desabilita ações por ele).
 * Equipe mostra só nome, função e período (sem CPF/contatos do funcionário).
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /** Nível efetivo de quem consulta (view/edit/manage); definido pelo controller. */
    public ?string $viewerLevel = null;

    public function withViewerLevel(?string $level): static
    {
        $this->viewerLevel = $level;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'progress' => $this->progress,
            'contract_value' => $this->contract_value,
            'start_date' => $this->start_date?->toDateString(),
            'expected_end_date' => $this->expected_end_date?->toDateString(),
            'actual_end_date' => $this->actual_end_date?->toDateString(),
            'access_level' => $this->when($this->viewerLevel !== null, fn () => $this->viewerLevel),
            'company' => $this->whenLoaded('company', fn () => ['uuid' => $this->company->uuid, 'legal_name' => $this->company->legal_name]),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->only(['uuid', 'name'])),
            'technical_manager' => $this->whenLoaded('technicalManager', fn () => $this->technicalManager?->only(['uuid', 'name'])),
            'addresses' => AddressResource::collection($this->whenLoaded('addresses')),
            'phases' => $this->whenLoaded('phases', fn () => $this->phases->sortBy(['sort_order', 'id'])->values()->map(fn ($p) => [
                'id' => $p->id, 'parent_id' => $p->parent_id, 'name' => $p->name, 'sort_order' => $p->sort_order,
                'planned_start' => $p->planned_start?->toDateString(), 'planned_end' => $p->planned_end?->toDateString(),
            ])),
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($m) => [
                'id' => $m->id, 'employee' => $m->employee?->only(['uuid', 'name']), 'role' => $m->role,
                'started_on' => $m->started_on?->toDateString(), 'ended_on' => $m->ended_on?->toDateString(),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
