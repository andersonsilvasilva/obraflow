<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linha do aditivo: `change` (quantidade de item existente) ou `new` (item incluído). D-P48. */
class ContractAddendumItem extends Model
{
    use BelongsToTenant;

    public const TYPES = ['change', 'new'];

    protected $fillable = ['line_type', 'contract_item_id', 'code', 'description', 'unit_code', 'unit_price', 'quantity_delta'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'quantity_delta' => 'decimal:4'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class, 'contract_item_id');
    }
}
