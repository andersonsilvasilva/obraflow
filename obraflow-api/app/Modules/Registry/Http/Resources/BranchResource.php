<?php

namespace App\Modules\Registry\Http\Resources;

use App\Models\CompanyBranch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CompanyBranch */
class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_uuid' => $this->whenLoaded('company', fn () => $this->company->uuid),
            'name' => $this->name,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'addresses' => AddressResource::collection($this->whenLoaded('addresses')),
            'contacts' => ContactResource::collection($this->whenLoaded('contacts')),
        ];
    }
}
