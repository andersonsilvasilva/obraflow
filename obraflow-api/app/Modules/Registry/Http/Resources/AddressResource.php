<?php

namespace App\Modules\Registry\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind, 'street' => $this->street, 'number' => $this->number,
            'complement' => $this->complement, 'district' => $this->district, 'city' => $this->city,
            'state' => $this->state, 'postal_code' => $this->postal_code,
            'latitude' => $this->latitude, 'longitude' => $this->longitude,
        ];
    }
}
