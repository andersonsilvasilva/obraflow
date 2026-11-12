<?php

namespace App\Modules\Access\Console;

use App\Enums\UserStatus;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UnblockUserCommand extends Command
{
    protected $signature = 'obraflow:user:unblock {email}';

    protected $description = 'Desbloqueia um usuário (os tokens revogados no bloqueio NÃO são restaurados).';

    public function handle(AuditLogger $audit): int
    {
        $user = User::query()->where('email', Str::lower((string) $this->argument('email')))->first();

        if (! $user) {
            $this->error('Usuário não encontrado.');

            return self::FAILURE;
        }
        if ($user->isActive()) {
            $this->warn('O usuário já está ativo.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $audit): void {
            $user->forceFill(['status' => UserStatus::Active])->save();
            $audit->record(AuditEvent::AUTH_USER_UNBLOCKED, userId: null, entityType: 'user', entityId: $user->getKey(),
                old: ['status' => 'blocked'], new: ['status' => 'active'], metadata: ['source' => 'artisan']);
        });

        $this->info('Usuário desbloqueado.');

        return self::SUCCESS;
    }
}
