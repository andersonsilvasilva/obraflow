<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Convite. Guarda só o HASH do token. Criado/alterado apenas por InvitationService (forceFill).
 */
class UserInvitation extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected $hidden = ['token_hash'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::Pending;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** @param  Builder<UserInvitation>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', InvitationStatus::Pending->value);
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
