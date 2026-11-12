<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantDomain extends Model
{
    use BelongsToTenant;

    protected $fillable = ['domain', 'is_primary', 'verified_at'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'verified_at' => 'datetime'];
    }
}
