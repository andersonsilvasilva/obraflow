<?php

namespace App\Modules\Teams\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Funcionário. CPF completo só para quem tem `employees.manage`; os demais recebem a máscara (D-P21).
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $full = (bool) $request->user()?->can('employees.manage');

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'registration_number' => $this->registration_number,
            'document_number' => $full ? $this->document_number : $this->maskedDocument(),
            'phone' => $this->phone,
            'email' => $this->email,
            'labor_type' => $this->labor_type,
            'contract_type' => $this->contract_type,
            'hired_at' => $this->hired_at?->toDateString(),
            'terminated_at' => $this->terminated_at?->toDateString(),
            'status' => $this->status,
            'company' => $this->whenLoaded('company', fn () => ['uuid' => $this->company->uuid, 'legal_name' => $this->company->legal_name]),
            'job_title' => $this->whenLoaded('jobTitle', fn () => $this->jobTitle?->only(['id', 'name'])),
            'department' => $this->whenLoaded('department', fn () => $this->department?->only(['id', 'name'])),
            'supervisor' => $this->whenLoaded('supervisor', fn () => $this->supervisor?->only(['uuid', 'name'])),
            'user' => $this->whenLoaded('user', fn () => $this->user?->only(['uuid', 'name'])),
            'certifications' => CertificationResource::collection($this->whenLoaded('certifications')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
