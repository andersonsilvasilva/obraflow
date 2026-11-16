<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cargo/função por tenant (D-P20). */
class JobTitle extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'status'];

    protected $attributes = ['status' => 'active'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
