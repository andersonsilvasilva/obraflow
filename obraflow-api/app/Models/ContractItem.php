<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item do contrato (planilha contratual). `total` e `quantity`/`original_quantity` são mantidos pelo ContractService
 * (total = arred2(quantidade × preço) — D-P32); com o contrato ativo, o item só muda por aditivo aprovado (D-P48).
 */
class ContractItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['code', 'description', 'unit_code', 'unit_price', 'sort_order'];

    protected $attributes = ['total' => '0.00'];

    protected function casts(): array
    {
        return [
            'original_quantity' => 'decimal:4', 'quantity' => 'decimal:4', 'unit_price' => 'decimal:2', 'total' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function addendum(): BelongsTo
    {
        return $this->belongsTo(ContractAddendum::class, 'addendum_id');
    }
}
