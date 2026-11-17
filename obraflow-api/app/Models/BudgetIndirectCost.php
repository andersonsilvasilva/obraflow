<?php

namespace App\Models;

use App\Modules\Budgets\Concerns\BelongsToBudgetVersion;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Custo indireto da versão (D-P02): fora do BDI; valor fixo ou % do custo direto. */
class BudgetIndirectCost extends Model
{
    use BelongsToBudgetVersion, BelongsToTenant;

    public const METHODS = ['fixed', 'percent_of_direct'];

    protected $fillable = ['budget_version_id', 'description', 'method', 'percent', 'amount', 'sort_order'];

    protected function casts(): array
    {
        return ['percent' => 'decimal:4', 'amount' => 'decimal:2', 'sort_order' => 'integer'];
    }
}
