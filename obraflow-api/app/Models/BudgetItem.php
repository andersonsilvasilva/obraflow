<?php

namespace App\Models;

use App\Modules\Budgets\Concerns\BelongsToBudgetVersion;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item do orçamento: cópia autossuficiente (D-08). `total_cost`/`total_sale` NÃO são preenchíveis — só o
 * BudgetCalculator os grava (D-P32). `bdi_percent` NULL = herda da etapa/global.
 */
class BudgetItem extends Model
{
    use BelongsToBudgetVersion, BelongsToTenant;

    public const SOURCES = ['official', 'custom', 'manual', 'catalog'];

    protected $fillable = [
        'budget_version_id', 'group_id', 'code', 'description', 'unit_code', 'quantity', 'unit_cost',
        'bdi_percent', 'discount_percent', 'source', 'source_ref', 'sort_order',
    ];

    protected $attributes = ['discount_percent' => '0.0000', 'total_cost' => '0.00', 'total_sale' => '0.00', 'source' => 'manual'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4', 'unit_cost' => 'decimal:2', 'bdi_percent' => 'decimal:4', 'discount_percent' => 'decimal:4',
            'total_cost' => 'decimal:2', 'total_sale' => 'decimal:2', 'source_ref' => 'array', 'sort_order' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(BudgetGroup::class, 'group_id');
    }
}
