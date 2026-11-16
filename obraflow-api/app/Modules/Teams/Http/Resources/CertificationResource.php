<?php

namespace App\Modules\Teams\Http\Resources;

use App\Models\EmployeeCertification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeCertification */
class CertificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'issued_at' => $this->issued_at?->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'expired' => $this->isExpired(),
            'document' => $this->whenLoaded('document', fn () => $this->document?->only(['uuid', 'title'])),
        ];
    }
}
