<?php

namespace App\Models;

use App\Modules\Budgets\Concerns\BelongsToBudgetVersion;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Insumo final de um item (árvore expandida na cópia): coeficiente acumulado por unidade do item. */
class BudgetItemResource extends Model
{
    use BelongsToBudgetVersion, BelongsToTenant;

    protected $fillable = ['budget_version_id', 'budget_item_id', 'kind', 'code', 'description', 'unit_code', 'coefficient', 'unit_price'];

    protected function casts(): array
    {
        return ['coefficient' => 'decimal:7', 'unit_price' => 'decimal:2'];
    }
}
