<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Balanço" da mão de obra por data de vigência (D-P39). `hourly_cost` só é gravado pelo serviço. */
class ResourceLaborCost extends Model
{
    use BelongsToTenant;

    protected $fillable = ['resource_id', 'effective_date', 'base_salary', 'extra_hour', 'charges_percent', 'admin_percent', 'monthly_hours', 'labor_type', 'contract_type'];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date', 'base_salary' => 'decimal:2', 'extra_hour' => 'decimal:2', 'charges_percent' => 'decimal:4',
            'admin_percent' => 'decimal:4', 'monthly_hours' => 'decimal:2', 'hourly_cost' => 'decimal:2',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
