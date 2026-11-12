<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\UserStatus;
use App\Modules\Access\Services\PermissionCatalog;
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
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;
use Spatie\Permission\Contracts\Permission as PermissionContract;
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
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    use HasRoles {
        hasPermissionTo as protected spatieHasPermissionTo;
    }

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

    /**
     * Autorização efetiva (D-29). Passa por aqui o Gate do Spatie, o middleware `can:` e `$user->can()`:
     *  - sessão (SPA): só as permissões do usuário no tenant ativo;
     *  - token: a INTERSEÇÃO entre as `abilities` do token e as permissões que o usuário tem AGORA no tenant.
     *    Wildcard (`*`) nunca vale; abilities de gestão de acesso nunca valem para token.
     *
     * @param  mixed  $permission
     * @param  string|null  $guardName
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        if (! $this->tokenAllows($permission)) {
            return false;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    /** Nomes das permissões efetivas (para `/auth/me`): as do usuário, reduzidas pelas abilities se for token. */
    public function effectivePermissionNames(): array
    {
        return $this->getAllPermissions()->pluck('name')
            ->filter(fn (string $name) => $this->tokenAllows($name))
            ->sort()->values()->all();
    }

    private function tokenAllows(mixed $permission): bool
    {
        $token = $this->currentAccessToken();

        if (! $token instanceof SanctumToken) {
            return true; // sessão ou contexto sem token: sem restrição por abilities
        }

        $name = match (true) {
            is_string($permission) => $permission,
            $permission instanceof PermissionContract => $permission->name,
            default => null, // formato desconhecido: falha fechada
        };

        if ($name === null || in_array($name, PermissionCatalog::RESTRICTED_FOR_TOKENS, true)) {
            return false;
        }

        // Comparação EXATA: o curinga '*' do Sanctum é deliberadamente ignorado.
        return in_array($name, $token->abilities ?? [], true);
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
