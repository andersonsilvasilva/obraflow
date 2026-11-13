<?php

namespace App\Modules\Registry\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'document_number' => $this->document_number,
            'state_registration' => $this->state_registration,
            'municipal_registration' => $this->municipal_registration,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'branches' => BranchResource::collection($this->whenLoaded('branches')),
            'addresses' => AddressResource::collection($this->whenLoaded('addresses')),
            'contacts' => ContactResource::collection($this->whenLoaded('contacts')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
