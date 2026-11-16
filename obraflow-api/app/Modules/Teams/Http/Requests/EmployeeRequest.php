<?php

namespace App\Modules\Teams\Http\Requests;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Registry\Rules\TaxDocument;
use Illuminate\Validation\Rule;

/**
 * Funcionários (D-P21: dados pessoais mínimos). Empresa só na criação. CPF (opcional, validado) e matrícula únicos
 * entre os ativos da empresa. Responsável: funcionário do tenant (mesma empresa conferida pelo modelo → 422).
 * Usuário ligado: membro ATIVO do tenant, ainda não ligado a outro funcionário ativo.
 */
class EmployeeRequest extends TenantRequest
{
    protected array $uuidRefs = [
        'company_uuid' => [Company::class, 'company_id'],
        'supervisor_uuid' => [Employee::class, 'supervisor_id'],
        'user_uuid' => [User::class, 'user_id'],
    ];

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $tenantId = $this->tenantId();
        $current = $this->current();
        $companyId = $current?->company_id ?? Company::query()->where('uuid', $this->input('company_uuid'))->value('id');
        $uniqueInCompany = fn (string $column) => Rule::unique('employees', $column)->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)->whereNull('deleted_at')->when($current, fn ($r) => $r->ignore($current->id));

        return [
            'company_uuid' => [$creating ? 'required' : 'prohibited', 'uuid', $this->existsInTenant('companies')],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'document_number' => ['nullable', 'string', 'max:14', new TaxDocument('cpf'), $uniqueInCompany('document_number')],
            'registration_number' => ['nullable', 'string', 'max:30', $uniqueInCompany('registration_number')],
            'job_title_id' => ['nullable', 'integer', $this->existsInTenant('job_titles', 'id', false)],
            'department_id' => ['nullable', 'integer', $this->existsInTenant('departments', 'id', false)],
            'supervisor_uuid' => ['nullable', 'uuid', $this->existsInTenant('employees'),
                Rule::notIn(array_filter([$current?->uuid]))],
            'user_uuid' => ['nullable', 'uuid',
                Rule::exists('users', 'uuid')->where(fn ($q) => $q->whereIn('id', fn ($m) => $m->select('user_id')
                    ->from('tenant_users')->where('tenant_id', $tenantId)->where('membership_status', 'active'))),
                function (string $attr, mixed $value, \Closure $fail) use ($current) {
                    $userId = User::query()->where('uuid', $value)->value('id');
                    $taken = Employee::query()->where('user_id', $userId)->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists();
                    if ($taken) {
                        $fail('Este usuário já está ligado a outro funcionário.');
                    }
                }],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'labor_type' => ['nullable', Rule::in(Employee::LABOR_TYPES)],
            'contract_type' => ['nullable', Rule::in(Employee::CONTRACT_TYPES)],
            'hired_at' => ['nullable', 'date'],
            'terminated_at' => array_filter(['nullable', 'date', $this->notBefore('hired_at', $current?->hired_at)]),
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public function current(): ?Employee
    {
        return $this->route('uuid') ? Employee::query()->where('uuid', $this->route('uuid'))->first() : null;
    }

    protected function prepareForValidation(): void
    {
        $this->digitsOnly('document_number');
    }
}
