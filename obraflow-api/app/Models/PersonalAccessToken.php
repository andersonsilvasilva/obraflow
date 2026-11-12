<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Token Sanctum com tenant (D-05/D-20). O Sanctum não conhece tenant: a emissão passa por IssueApiToken,
 * que grava `tenant_id` na mesma transação. Token com tenant_id NULL nunca acessa dados de negócio.
 */
class PersonalAccessToken extends SanctumToken
{
    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
