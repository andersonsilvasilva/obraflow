<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Aditivo do contrato (D-P48). Número, status, valor e aprovação são gravados pelo ContractAddendumService. */
class ContractAddendum extends Model
{
    use BelongsToTenant;

    public const KINDS = ['value', 'term', 'scope', 'mixed'];

    protected $table = 'contract_addenda';

    protected $fillable = ['kind', 'new_end_date', 'description', 'signed_at'];

    protected $attributes = ['status' => 'draft', 'value_delta' => '0.00'];

    protected function casts(): array
    {
        return [
            'value_delta' => 'decimal:2', 'new_end_date' => 'date', 'signed_at' => 'date', 'approved_at' => 'immutable_datetime',
            'number' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ContractAddendumItem::class, 'addendum_id')->orderBy('id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
