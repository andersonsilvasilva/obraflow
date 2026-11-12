<?php

namespace App\Modules\Access\Console;

use App\Enums\UserStatus;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Access\Services\LastAdminGuard;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Bloqueio global de usuário (administração de plataforma só via CLI nesta fase). */
class BlockUserCommand extends Command
{
    protected $signature = 'obraflow:user:block {email} {--force : Bloqueia mesmo sendo o último administrador de algum tenant}';

    protected $description = 'Bloqueia um usuário: perde sessões e tokens imediatamente.';

    public function handle(LastAdminGuard $guard, AuditLogger $audit): int
    {
        $user = User::query()->where('email', Str::lower((string) $this->argument('email')))->first();

        if (! $user) {
            $this->error('Usuário não encontrado.');

            return self::FAILURE;
        }
        if (! $user->isActive()) {
            $this->warn('O usuário já está bloqueado.');

            return self::SUCCESS;
        }

        $risky = Tenant::query()->whereIn('id', TenantUser::query()->where('user_id', $user->getKey())->pluck('tenant_id'))->get()
            ->filter(fn (Tenant $t) => $guard->isActiveAdmin($t, $user->getKey()) && $guard->remainingAdmins($t, $user->getKey()) === 0);

        if ($risky->isNotEmpty() && ! $this->option('force')) {
            $this->error('Recusado: este é o último administrador ativo de: '.$risky->pluck('slug')->implode(', ').'. Use --force para ignorar.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $audit): void {
            $revoked = PersonalAccessToken::query()->where('tokenable_id', $user->getKey())->delete();
            $user->forceFill(['status' => UserStatus::Blocked, 'remember_token' => Str::random(60)])->save();
            $audit->record(AuditEvent::AUTH_USER_BLOCKED, userId: null, entityType: 'user', entityId: $user->getKey(),
                old: ['status' => 'active'], new: ['status' => 'blocked'], metadata: ['source' => 'artisan', 'tokens_revoked' => $revoked]);
        });

        $this->info('Usuário bloqueado.');

        return self::SUCCESS;
    }
}
