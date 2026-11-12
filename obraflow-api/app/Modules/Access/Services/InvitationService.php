<?php

namespace App\Modules\Access\Services;

use App\Enums\InvitationStatus;
use App\Enums\MembershipStatus;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\UserInvitation;
use App\Modules\Access\Mail\InvitationMail;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Auth\PasswordRules;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Convites por e-mail. O token em claro existe só no e-mail; no banco fica o HASH. O vínculo só nasce na aceitação.
 * Criar/revogar/aceitar/expirar são auditados na mesma transação da mudança.
 */
final class InvitationService
{
    public function __construct(
        private readonly TenantLock $lock,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly PrivilegeGuard $privileges,
    ) {}

    /** @return array{0: UserInvitation, 1: bool} convite e se o e-mail foi enviado */
    public function create(User $actor, Tenant $tenant, string $email, Role $role): array
    {
        $email = Str::lower(trim($email));

        if ((int) $role->tenant_id !== (int) $tenant->getKey()) {
            throw ApiException::notFound('Papel não encontrado.');
        }
        $this->privileges->assertCanGrantRole($actor, $tenant, $role);

        $plain = Str::random(48);

        $invitation = $this->lock->run($tenant, function () use ($actor, $tenant, $email, $role, $plain): UserInvitation {
            $existing = User::query()->where('email', $email)->first();
            $membership = $existing
                ? TenantUser::query()->where('tenant_id', $tenant->getKey())->where('user_id', $existing->getKey())->first()
                : null;

            if ($membership?->membership_status === MembershipStatus::Active) {
                throw ApiException::conflict('already_member', 'Este e-mail já pertence a um membro ativo do tenant.');
            }
            if ($membership?->membership_status === MembershipStatus::Suspended) {
                throw ApiException::conflict('membership_suspended', 'Este usuário está suspenso neste tenant. Reative o vínculo em vez de convidar.');
            }

            return $this->context->run($tenant, function () use ($actor, $tenant, $email, $role, $plain): UserInvitation {
                if (UserInvitation::query()->pending()->where('email', $email)->exists()) {
                    throw ApiException::conflict('invitation_pending', 'Já existe um convite pendente para este e-mail. Revogue-o para criar outro.');
                }

                $invitation = new UserInvitation;
                $invitation->forceFill([
                    'email' => $email,
                    'invited_by' => $actor->getKey(),
                    'role_id' => $role->getKey(),
                    'token_hash' => UserInvitation::hashToken($plain),
                    'status' => InvitationStatus::Pending,
                    'expires_at' => now()->addDays((int) config('obraflow.invitation_ttl_days')),
                ])->save();

                $this->audit->record(AuditEvent::INVITATION_CREATED, tenantId: $tenant->getKey(), userId: $actor->getKey(),
                    entityType: 'invitation', entityId: $invitation->getKey(),
                    new: ['email' => $email, 'role' => $role->name, 'expires_at' => $invitation->expires_at->toIso8601String()]);

                return $invitation;
            });
        });

        // Fora da transação: falha de e-mail não desfaz o convite (o admin pode revogar e recriar).
        $sent = true;
        try {
            Mail::to($email)->send(new InvitationMail($tenant->name, $actor->name, $role->name, $plain, $invitation->expires_at->format('d/m/Y H:i')));
        } catch (Throwable $e) {
            $sent = false;
            Log::error('Falha ao enviar convite', ['invitation_id' => $invitation->getKey(), 'exception' => $e::class]);
        }

        return [$invitation, $sent];
    }

    public function revoke(User $actor, Tenant $tenant, UserInvitation $invitation): UserInvitation
    {
        return $this->lock->run($tenant, function () use ($actor, $tenant, $invitation): UserInvitation {
            $invitation = UserInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());

            if (! $invitation->isPending()) {
                throw ApiException::conflict('invalid_invitation_state', 'Só convites pendentes podem ser revogados.');
            }

            $invitation->forceFill(['status' => InvitationStatus::Revoked, 'revoked_at' => now()])->save();

            $this->audit->record(AuditEvent::INVITATION_REVOKED, tenantId: $tenant->getKey(), userId: $actor->getKey(),
                entityType: 'invitation', entityId: $invitation->getKey(),
                old: ['status' => 'pending'], new: ['status' => 'revoked'], metadata: ['email' => $invitation->email]);

            return $invitation;
        });
    }

    /**
     * Aceite PÚBLICO (o token é a credencial). A busca é deliberadamente SEM escopo de tenant.
     *
     * @param  array<string, mixed>  $data  token, name?, password?, password_confirmation?
     * @return array{0: Tenant, 1: User, 2: bool} tenant, usuário e se a conta já existia
     */
    public function accept(array $data): array
    {
        $hash = UserInvitation::hashToken((string) $data['token']);
        $found = UserInvitation::query()->withoutGlobalScopes()->where('token_hash', $hash)->first();

        // Desconhecido, usado ou revogado: mesma resposta (não revela o estado).
        if (! $found || $found->status !== InvitationStatus::Pending) {
            throw new ApiException(410, 'invitation_invalid', 'Este convite é inválido ou já foi utilizado.');
        }

        $tenant = Tenant::query()->find($found->tenant_id);
        if (! $tenant || ! $tenant->allowsAccess()) {
            throw ApiException::forbidden('tenant_suspended', 'Este tenant está suspenso ou cancelado.');
        }

        if ($found->isExpired()) {
            $this->expire($found);
            throw new ApiException(410, 'invitation_expired', 'Este convite expirou. Peça um novo ao administrador.');
        }

        // O contexto do tenant do CONVITE é ativado aqui (rota pública, sem sessão): o escopo global de
        // UserInvitation exige tenant ativo até para o UPDATE.
        return $this->lock->run($tenant, fn () => $this->context->run($tenant, function () use ($found, $tenant, $data): array {
            $invitation = UserInvitation::query()->lockForUpdate()->findOrFail($found->getKey());

            if ($invitation->status !== InvitationStatus::Pending) {
                throw new ApiException(410, 'invitation_invalid', 'Este convite é inválido ou já foi utilizado.');
            }

            $user = User::query()->where('email', $invitation->email)->first();
            $existed = $user !== null;

            if ($user && ! $user->isActive()) {
                throw ApiException::forbidden('account_blocked', 'Esta conta está bloqueada.');
            }

            if (! $user) {
                $validated = Validator::make($data, [
                    'name' => ['required', 'string', 'max:150'],
                    'password' => ['required', 'string', 'confirmed', PasswordRules::strong()],
                ])->validate();

                $user = new User;
                $user->forceFill([
                    'name' => $validated['name'], 'email' => $invitation->email, 'password' => $validated['password'],
                    'status' => UserStatus::Active, 'email_verified_at' => now(), // o token provou o controle do e-mail
                ])->save();
            }

            $membership = TenantUser::query()->where('tenant_id', $tenant->getKey())->where('user_id', $user->getKey())->first();

            if ($membership && $membership->membership_status !== MembershipStatus::Removed) {
                throw ApiException::conflict(
                    $membership->membership_status === MembershipStatus::Active ? 'already_member' : 'membership_suspended',
                    'Este usuário já possui vínculo com o tenant.',
                );
            }

            $membership ??= new TenantUser;
            $reactivated = $membership->exists;
            $membership->forceFill([
                'tenant_id' => $tenant->getKey(), 'user_id' => $user->getKey(),
                'membership_status' => MembershipStatus::Active, 'joined_at' => now(),
            ])->save();

            $role = Role::query()->forTenant($tenant->getKey())->findOrFail($invitation->role_id);

            $user->assignRole($role); // o contexto do Spatie já aponta para este tenant (context->run acima)
            $user->unsetRelation('roles')->unsetRelation('permissions');

            $invitation->forceFill([
                'status' => InvitationStatus::Accepted, 'accepted_at' => now(), 'accepted_user_id' => $user->getKey(),
            ])->save();

            $this->audit->record(AuditEvent::INVITATION_ACCEPTED, tenantId: $tenant->getKey(), userId: $user->getKey(),
                entityType: 'invitation', entityId: $invitation->getKey(), new: ['status' => 'accepted'],
                metadata: ['account_existed' => $existed, 'invited_by' => $invitation->invited_by]);
            $this->audit->record($reactivated ? AuditEvent::MEMBERSHIP_REACTIVATED : AuditEvent::MEMBERSHIP_CREATED,
                tenantId: $tenant->getKey(), userId: $user->getKey(), entityType: 'tenant_user', entityId: $membership->getKey(),
                new: ['membership_status' => 'active'], metadata: ['target_user_id' => $user->getKey(), 'via' => 'invitation']);
            $this->audit->record(AuditEvent::ROLE_ASSIGNED, tenantId: $tenant->getKey(), userId: $user->getKey(),
                entityType: 'tenant_user', entityId: $membership->getKey(), new: ['role' => $role->name],
                metadata: ['target_user_id' => $user->getKey(), 'role_id' => $role->getKey(), 'via' => 'invitation']);

            return [$tenant, $user, $existed];
        }, null));
    }

    /** Marca como expirado (rotina agendada e aceite tardio). Sem ator: evento de sistema. */
    public function expire(UserInvitation $invitation): void
    {
        $tenant = Tenant::query()->withTrashed()->find($invitation->tenant_id);
        if (! $tenant) {
            return;
        }

        $this->lock->run($tenant, fn () => $this->context->run($tenant, function () use ($invitation): void {
            $locked = UserInvitation::query()->lockForUpdate()->find($invitation->getKey());

            if (! $locked || $locked->status !== InvitationStatus::Pending) {
                return;
            }

            $locked->forceFill(['status' => InvitationStatus::Expired])->save();

            $this->audit->record(AuditEvent::INVITATION_EXPIRED, tenantId: $locked->tenant_id, userId: null,
                entityType: 'invitation', entityId: $locked->getKey(),
                old: ['status' => 'pending'], new: ['status' => 'expired'], metadata: ['email' => $locked->email, 'source' => 'system']);
        }));
    }
}
