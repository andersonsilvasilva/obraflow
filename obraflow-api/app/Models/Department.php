<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Setor por tenant (D-P20). */
class Department extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'status'];

    protected $attributes = ['status' => 'active'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
