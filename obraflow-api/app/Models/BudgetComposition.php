<?php

namespace App\Models;

use App\Modules\Budgets\Concerns\BelongsToBudgetVersion;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Composição copiada da referência para um item (D-08): autossuficiente, com a origem em `source_snapshot`. */
class BudgetComposition extends Model
{
    use BelongsToBudgetVersion, BelongsToTenant;

    protected $fillable = ['budget_version_id', 'budget_item_id', 'code', 'description', 'unit_code', 'unit_cost', 'source_snapshot'];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:2', 'source_snapshot' => 'array'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BudgetCompositionItem::class)->orderBy('sort_order');
    }
}
