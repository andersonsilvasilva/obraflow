<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vínculo usuário ↔ empresa (D-P25): registrado, ainda SEM efeito de bloqueio. */
class UserCompany extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'company_id', 'status'];

    protected $attributes = ['status' => 'active'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
