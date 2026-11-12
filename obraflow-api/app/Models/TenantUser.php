<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vínculo usuário ↔ tenant. NÃO usa BelongsToTenant: é o modelo que ESTABELECE o contexto
 * (o middleware o consulta antes de haver tenant ativo). Toda consulta aqui é explícita por tenant/usuário.
 */
class TenantUser extends Model
{
    protected $table = 'tenant_users';

    protected $guarded = ['*']; // criado apenas por serviços via forceFill

    protected $attributes = ['membership_status' => 'active'];

    protected function casts(): array
    {
        return [
            'membership_status' => MembershipStatus::class,
            'joined_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->membership_status === MembershipStatus::Active;
    }

    /** @param  Builder<TenantUser>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('membership_status', MembershipStatus::Active->value);
    }
}
