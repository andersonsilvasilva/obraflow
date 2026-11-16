<?php

namespace App\Models;

use App\Modules\Registry\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Equipamento por empresa. Sem custo (Fase 4). `group_kind` é fixo ('equipment') e não é preenchível:
 * só existe para a FK garantir que o grupo é de equipamentos.
 */
class Equipment extends Model
{
    use BelongsToTenant, HasPublicUuid, SoftDeletes;

    public const OWNERSHIPS = ['owned', 'rented'];

    public const STATUSES = ['available', 'in_use', 'maintenance', 'retired'];

    protected $table = 'equipment';

    protected $fillable = [
        'company_id', 'group_id', 'code', 'name', 'brand', 'model', 'kind', 'condition',
        'unit_id', 'main_supplier_id', 'ownership', 'status',
    ];

    protected $attributes = ['group_kind' => 'equipment', 'ownership' => 'owned', 'status' => 'available'];

    protected $hidden = ['group_kind'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ResourceGroup::class, 'group_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    public function mainSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'main_supplier_id');
    }
}
