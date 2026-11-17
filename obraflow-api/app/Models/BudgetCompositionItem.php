<?php

namespace App\Models;

use App\Modules\Budgets\Concerns\BelongsToBudgetVersion;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Item direto da composição copiada (insumo ou composição auxiliar), como publicado. */
class BudgetCompositionItem extends Model
{
    use BelongsToBudgetVersion, BelongsToTenant;

    protected $fillable = [
        'budget_version_id', 'budget_composition_id', 'item_type', 'kind', 'code', 'description', 'unit_code',
        'coefficient', 'unit_price', 'cost', 'price_origin', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['coefficient' => 'decimal:7', 'unit_price' => 'decimal:2', 'cost' => 'decimal:2', 'sort_order' => 'integer'];
    }
}
