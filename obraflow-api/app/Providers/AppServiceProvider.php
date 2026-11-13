<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantSetting;
use App\Models\TenantUser;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\UserInvitation;
use App\Modules\Access\Console\BlockUserCommand;
use App\Modules\Access\Console\ExpireInvitationsCommand;
use App\Modules\Access\Console\ProvisionTenantCommand;
use App\Modules\Access\Console\SyncPermissionsCommand;
use App\Modules\Access\Console\TenantStatusCommand;
use App\Modules\Access\Console\UnblockUserCommand;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Core\Health\DatabaseCheck;
use App\Modules\Core\Health\HealthReport;
use App\Modules\Core\Health\RedisCheck;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Uma instância por requisição/job (seguro para Octane/filas).
        $this->app->scoped(TenantContext::class);

        $this->app->bind(HealthReport::class, function ($app) {
            $checks = [$app->make(DatabaseCheck::class)];

            // Redis só é dependência quando algum driver o utiliza (Redis está adiado no ambiente local).
            if ($this->redisInUse()) {
                $checks[] = $app->make(RedisCheck::class);
            }

            return new HealthReport($checks);
        });
    }

    public function boot(): void
    {
        // OBRIGATÓRIO (D-19): model_type/tokenable_type = 'user' satisfaz os CHECK das tabelas de acesso.
        // enforceMorphMap faz qualquer classe fora do mapa lançar erro em vez de gravar o nome completo.
        Relation::enforceMorphMap([
            'user' => User::class,
            'tenant' => Tenant::class,
            'tenant_user' => TenantUser::class,
            'tenant_domain' => TenantDomain::class,
            'tenant_setting' => TenantSetting::class,
            'role' => Role::class,
            'invitation' => UserInvitation::class,
            'token' => PersonalAccessToken::class,
            'audit_log' => AuditLog::class,
            // Fase 2 — os 4 primeiros são os únicos aceitos em document_links.linkable_type (CHECK)
            'company' => Company::class,
            'company_branch' => CompanyBranch::class,
            'customer' => Customer::class,
            'supplier' => Supplier::class,
            'unit_of_measure' => UnitOfMeasure::class,
            'document' => Document::class,
            'document_version' => DocumentVersion::class,
        ]);

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->configureRateLimiting();
        $this->configureMailLinks();
        $this->auditExpiredTokenUse();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncPermissionsCommand::class,
                ProvisionTenantCommand::class,
                BlockUserCommand::class,
                UnblockUserCommand::class,
                TenantStatusCommand::class,
                ExpireInvitationsCommand::class,
            ]);
        }
    }

    private function configureRateLimiting(): void
    {
        // Por e-mail+IP: freia força bruta sem permitir que um atacante trave a conta de outro IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(3)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
    }

    private function configureMailLinks(): void
    {
        // Link de recuperação aponta para o FRONTEND (SPA), não para a API.
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('obraflow.frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset()));
    }

    /** Uso de token expirado negado ⇒ evento token.expired_use_denied (melhor esforço; nunca o valor do token). */
    private function auditExpiredTokenUse(): void
    {
        Sanctum::authenticateAccessTokensUsing(function (mixed $accessToken, bool $isValid): bool {
            if (! $isValid && $accessToken instanceof PersonalAccessToken) {
                $maxMinutes = (int) config('sanctum.expiration');
                $expired = $accessToken->expires_at?->isPast()
                    || ($maxMinutes > 0 && $accessToken->created_at?->lt(now()->subMinutes($maxMinutes)));

                if ($expired) {
                    app(AuditLogger::class)->recordBestEffort(
                        AuditEvent::TOKEN_EXPIRED_USE_DENIED,
                        tenantId: $accessToken->tenant_id,
                        userId: $accessToken->tokenable_id,
                        entityType: 'token',
                        entityId: $accessToken->getKey(),
                        metadata: ['token_id' => $accessToken->getKey()],
                    );
                }
            }

            return $isValid;
        });
    }

    private function redisInUse(): bool
    {
        return config('cache.default') === 'redis'
            || config('session.driver') === 'redis'
            || config('queue.default') === 'redis';
    }
}
