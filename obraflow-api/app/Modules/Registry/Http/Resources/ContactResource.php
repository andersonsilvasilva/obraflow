<?php

namespace App\Modules\Registry\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'role' => $this->role,
            'email' => $this->email, 'phone' => $this->phone, 'is_primary' => (bool) $this->is_primary,
        ];
    }
}
