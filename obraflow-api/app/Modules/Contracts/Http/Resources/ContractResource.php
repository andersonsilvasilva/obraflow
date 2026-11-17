<?php

namespace App\Modules\Contracts\Http\Resources;

use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato. Valores monetários como TEXTO exato (D-P32). `abilities` = o que quem consulta pode fazer (o frontend
 * habilita/desabilita; a API confere de novo).
 *
 * @mixin Contract
 */
class ContractResource extends JsonResource
{
    /** @var array<string, bool>|null */
    public ?array $abilities = null;

    public function withAbilities(array $abilities): static
    {
        $this->abilities = $abilities;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'kind' => $this->kind,
            'status' => $this->status,
            'object' => $this->object,
            'original_value' => (string) $this->original_value,
            'current_value' => (string) $this->current_value,
            'starts_at' => $this->starts_at?->toDateString(),
            'original_end_date' => $this->original_end_date?->toDateString(),
            'current_end_date' => $this->current_end_date?->toDateString(),
            'signed_at' => $this->signed_at?->toDateString(),
            'retention_percent' => (string) $this->retention_percent,
            'readjustment_rule' => $this->readjustment_rule,
            'notes' => $this->notes,
            'company' => $this->whenLoaded('company', fn () => ['uuid' => $this->company->uuid, 'legal_name' => $this->company->legal_name]),
            'project' => $this->whenLoaded('project', fn () => $this->project?->only(['uuid', 'code', 'name'])),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->only(['uuid', 'name'])),
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? ['uuid' => $this->supplier->uuid, 'name' => $this->supplier->trade_name ?: $this->supplier->legal_name] : null),
            'budget' => $this->whenLoaded('budgetVersion', fn () => $this->budgetVersion ? [
                'uuid' => $this->budgetVersion->budget?->uuid, 'code' => $this->budgetVersion->budget?->code, 'version' => $this->budgetVersion->version_number,
            ] : null),
            'items_count' => $this->when($this->items_count !== null, fn () => (int) $this->items_count),
            'documents_count' => $this->when($this->documents_count !== null, fn () => (int) $this->documents_count),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->only(['uuid', 'name'])),
            'abilities' => $this->when($this->abilities !== null, fn () => $this->abilities),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
