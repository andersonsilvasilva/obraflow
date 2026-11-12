<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Identidade GLOBAL: não possui tenant_id. O vínculo com tenants é tenant_users.
 * `status` e `last_login_at` NÃO são mass-assignable: só os serviços de acesso os alteram.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    /** Guard único para sessão (web) e token (sanctum): evita permissões divididas por guard (R9). */
    protected $guard_name = 'web';

    protected $attributes = ['status' => 'active'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /** @return HasMany<TenantUser, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    /** @return BelongsToMany<Tenant, $this> */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot(['membership_status', 'joined_at', 'last_used_at'])
            ->withTimestamps();
    }

    /** Tenants nos quais o usuário tem vínculo ATIVO. */
    public function activeTenants(): BelongsToMany
    {
        return $this->tenants()->wherePivot('membership_status', MembershipStatus::Active->value);
    }
}
