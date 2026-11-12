<?php

namespace App\Modules\Access\Console;

use App\Enums\InvitationStatus;
use App\Models\UserInvitation;
use App\Modules\Access\Services\InvitationService;
use Illuminate\Console\Command;

/** Expira convites pendentes vencidos (agendado de hora em hora). Cada um gera `invitation.expired`. */
class ExpireInvitationsCommand extends Command
{
    protected $signature = 'obraflow:invitations:expire';

    protected $description = 'Marca como expirados os convites pendentes cujo prazo venceu.';

    public function handle(InvitationService $service): int
    {
        $count = 0;

        UserInvitation::query()->withoutGlobalScopes()
            ->where('status', InvitationStatus::Pending->value)->where('expires_at', '<=', now())
            ->orderBy('id')->each(function (UserInvitation $invitation) use ($service, &$count): void {
                $service->expire($invitation);
                $count++;
            });

        $this->info("{$count} convite(s) expirado(s).");

        return self::SUCCESS;
    }
}
