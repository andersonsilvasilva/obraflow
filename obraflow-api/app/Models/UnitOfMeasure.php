<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Unidade de medida por tenant (D-P14). */
class UnitOfMeasure extends Model
{
    use BelongsToTenant;

    protected $table = 'units_of_measure';

    protected $fillable = ['code', 'name', 'status'];

    protected $attributes = ['status' => 'active'];
}
