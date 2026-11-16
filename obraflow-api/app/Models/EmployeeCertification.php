<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Certificação/treinamento do funcionário, com comprovante opcional (documento da Fase 2). */
class EmployeeCertification extends Model
{
    use BelongsToTenant;

    protected $fillable = ['employee_id', 'name', 'issued_at', 'expires_at', 'document_id'];

    protected function casts(): array
    {
        return ['issued_at' => 'date', 'expires_at' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
