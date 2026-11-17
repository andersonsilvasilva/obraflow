<?php

namespace App\Modules\Budgets\Http\Resources;

use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

/** @mixin Budget */
class BudgetResource extends JsonResource
{
    /** Ações permitidas a quem consulta (o frontend habilita/desabilita por elas). @var array<string, bool>|null */
    public ?array $abilities = null;

    public function withAbilities(array $abilities): static
    {
        $this->abilities = $abilities;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $current = $this->whenLoaded('currentVersion');

        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'title' => $this->title,
            'mode' => $this->mode,
            'status' => $this->status,
            'state' => $this->state,
            'social_charges_regime' => $this->social_charges_regime,
            'reference_date' => $this->reference_date?->toDateString(),
            'notes' => $this->notes,
            'company' => $this->whenLoaded('company', fn () => ['uuid' => $this->company->uuid, 'legal_name' => $this->company->legal_name]),
            'project' => $this->whenLoaded('project', fn () => $this->project?->only(['uuid', 'code', 'name'])),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->only(['uuid', 'name'])),
            'reference_dataset' => $this->whenLoaded('dataset', fn () => $this->dataset ? [
                'id' => $this->dataset->id, 'competency' => $this->dataset->version, 'state' => $this->dataset->state,
                'charges_regime' => $this->dataset->charges_regime, 'status' => $this->dataset->status,
            ] : null),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->only(['uuid', 'name'])),
            'current_version' => $this->when($current !== null && ! $current instanceof MissingValue, fn () => [
                'number' => $this->currentVersion->version_number, 'label' => $this->currentVersion->label, 'status' => $this->currentVersion->status,
                'locked' => $this->currentVersion->isLocked(), 'final_price' => $this->currentVersion->totals_snapshot['final_price'] ?? null,
                'direct_cost' => $this->currentVersion->totals_snapshot['direct_cost'] ?? null,
            ]),
            'versions' => $this->whenLoaded('versions', fn () => $this->versions->map(fn ($v) => [
                'number' => $v->version_number, 'label' => $v->label, 'status' => $v->status, 'locked' => $v->isLocked(),
                'approved_at' => $v->approved_at?->toIso8601String(), 'final_price' => $v->totals_snapshot['final_price'] ?? null,
            ])),
            'abilities' => $this->when($this->abilities !== null, fn () => $this->abilities),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
