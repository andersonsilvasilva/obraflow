<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['setting_key', 'setting_value'];

    protected function casts(): array
    {
        return ['setting_value' => 'array'];
    }
}
