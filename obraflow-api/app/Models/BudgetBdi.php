<?php

namespace App\Models;

use App\Modules\Budgets\Concerns\BelongsToBudgetVersion;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** BDI global, por etapa ou por item (D-P02). Gravado só pelo BudgetService (fórmula do TCU no BdiFormula). */
class BudgetBdi extends Model
{
    use BelongsToBudgetVersion, BelongsToTenant;

    protected $table = 'budget_bdi';

    protected $fillable = ['budget_version_id', 'scope', 'group_id', 'item_id', 'method', 'components', 'percent'];

    protected function casts(): array
    {
        return ['components' => 'array', 'percent' => 'decimal:4'];
    }
}
