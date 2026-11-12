<?php

namespace App\Modules\Audit;

/**
 * Catálogo de eventos de auditoria (formato dominio.acao). Fonte única — FASE1_MODELO.md §6.
 * Eventos de AUTORIZAÇÃO (tenant, convites, vínculos, papéis, permissões, tokens) são gravados na MESMA
 * transação da mudança. Eventos `auth.*` são melhor esforço.
 */
final class AuditEvent
{
    // Autenticação
    public const AUTH_LOGIN = 'auth.login';

    public const AUTH_LOGIN_FAILED = 'auth.login_failed';

    public const AUTH_LOGOUT = 'auth.logout';

    public const AUTH_PASSWORD_RESET_REQUESTED = 'auth.password_reset_requested';

    public const AUTH_PASSWORD_CHANGED = 'auth.password_changed';

    public const AUTH_SESSIONS_REVOKED = 'auth.sessions_revoked';

    public const AUTH_USER_BLOCKED = 'auth.user_blocked';

    public const AUTH_USER_UNBLOCKED = 'auth.user_unblocked';

    // Tenant
    public const TENANT_PROVISIONED = 'tenant.provisioned';

    public const TENANT_SELECTED = 'tenant.selected';

    public const TENANT_SELECTION_DENIED = 'tenant.selection_denied';

    public const TENANT_UPDATED = 'tenant.updated';

    public const TENANT_SUSPENDED = 'tenant.suspended';

    public const TENANT_REACTIVATED = 'tenant.reactivated';

    // Convites
    public const INVITATION_CREATED = 'invitation.created';

    public const INVITATION_ACCEPTED = 'invitation.accepted';

    public const INVITATION_REVOKED = 'invitation.revoked';

    public const INVITATION_EXPIRED = 'invitation.expired';

    // Vínculos
    public const MEMBERSHIP_CREATED = 'membership.created';

    public const MEMBERSHIP_SUSPENDED = 'membership.suspended';

    public const MEMBERSHIP_REACTIVATED = 'membership.reactivated';

    public const MEMBERSHIP_REMOVED = 'membership.removed';

    // Papéis e permissões
    public const ROLE_CREATED = 'role.created';

    public const ROLE_UPDATED = 'role.updated';

    public const ROLE_DELETED = 'role.deleted';

    public const ROLE_ASSIGNED = 'role.assigned';

    public const ROLE_REVOKED = 'role.revoked';

    public const PERMISSION_GRANTED = 'permission.granted';

    public const PERMISSION_REVOKED = 'permission.revoked';

    // Tokens
    public const TOKEN_CREATED = 'token.created';

    public const TOKEN_REVOKED = 'token.revoked';

    public const TOKEN_EXPIRED_USE_DENIED = 'token.expired_use_denied';

    /** @return list<string> */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
