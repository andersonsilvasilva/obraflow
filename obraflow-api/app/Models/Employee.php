<?php

namespace App\Models;

use App\Modules\Registry\Concerns\HasPublicUuid;
use App\Modules\Registry\Concerns\NormalizesDigits;
use App\Modules\Shared\Exceptions\DomainRuleException;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Funcionário (empregado ≠ usuário, D-11). Dados pessoais mínimos (D-P21).
 * Regras da aplicação: responsável ≠ o próprio funcionário e da mesma empresa; usuário ligado deve ser membro
 * do tenant (a FK composta → tenant_users garante no banco).
 */
class Employee extends Model
{
    use BelongsToTenant, HasPublicUuid, NormalizesDigits, SoftDeletes;

    public const LABOR_TYPES = ['direct', 'indirect'];

    public const CONTRACT_TYPES = ['monthly', 'hourly', 'piecework', 'daily'];

    protected $fillable = [
        'company_id', 'job_title_id', 'department_id', 'supervisor_id', 'user_id', 'registration_number', 'name',
        'document_number', 'phone', 'email', 'labor_type', 'contract_type', 'hired_at', 'terminated_at', 'status',
    ];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['hired_at' => 'date', 'terminated_at' => 'date'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $employee): void {
            if ($employee->supervisor_id === null) {
                return;
            }
            if ($employee->exists && (int) $employee->supervisor_id === (int) $employee->id) {
                throw new DomainRuleException('O funcionário não pode ser responsável por si mesmo.');
            }
            $supervisor = self::query()->find($employee->supervisor_id);
            if ($supervisor !== null && (int) $supervisor->company_id !== (int) $employee->company_id) {
                throw new DomainRuleException('O responsável deve ser da mesma empresa.');
            }
        });
    }

    protected function documentNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $v) => self::digits($v));
    }

    /** CPF mascarado para quem não administra funcionários (D-P21): ***.456.789-** */
    public function maskedDocument(): ?string
    {
        $d = $this->document_number;

        return $d === null ? null : '***.'.substr($d, 3, 3).'.'.substr($d, 6, 3).'-**';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(EmployeeCertification::class);
    }

    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }
}
