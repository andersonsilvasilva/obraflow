<?php

namespace App\Modules\Registry\Http\Resources;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Cliente ou fornecedor. */
class PartyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $names = $this->resource instanceof Supplier
            ? ['legal_name' => $this->legal_name, 'trade_name' => $this->trade_name]
            : ['name' => $this->name];

        return [
            'uuid' => $this->uuid,
            'company_uuid' => $this->whenLoaded('company', fn () => $this->company->uuid),
            'type' => $this->type,
            'document_number' => $this->document_number,
            ...$names,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'addresses' => AddressResource::collection($this->whenLoaded('addresses')),
            'contacts' => ContactResource::collection($this->whenLoaded('contacts')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
