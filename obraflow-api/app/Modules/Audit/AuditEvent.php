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

    // Fase 2 — cadastros (entity_type = alias do morph map: company, customer, company_address…)
    public const REGISTRY_CREATED = 'registry.created';

    public const REGISTRY_UPDATED = 'registry.updated';

    public const REGISTRY_DELETED = 'registry.deleted';

    // Fase 2 — documentos
    public const DOCUMENT_CREATED = 'document.created';

    public const DOCUMENT_UPDATED = 'document.updated';

    public const DOCUMENT_VERSION_ADDED = 'document.version_added';

    public const DOCUMENT_LINKED = 'document.linked';

    public const DOCUMENT_UNLINKED = 'document.unlinked';

    public const DOCUMENT_PERMISSIONS_CHANGED = 'document.permissions_changed';

    public const DOCUMENT_URL_ISSUED = 'document.url_issued';

    // Fase 3 — obras (o CRUD de equipes e obras usa registry.*, com entity_type employee, equipment, project…)
    public const PROJECT_STATUS_CHANGED = 'project.status_changed';

    public const PROJECT_ACCESS_GRANTED = 'project.access_granted';

    public const PROJECT_ACCESS_REVOKED = 'project.access_revoked';

    // Fase 4 — referências técnicas (globais: tenant_id NULL)
    public const REFERENCE_IMPORTED = 'reference.imported';

    public const REFERENCE_ACTIVATED = 'reference.activated';

    // Fase 4B — fluxo do orçamento (o CRUD usa registry.*, com entity_type budget, budget_item…)
    public const BUDGET_SUBMITTED = 'budget.submitted';

    public const BUDGET_WITHDRAWN = 'budget.withdrawn';

    public const BUDGET_APPROVED = 'budget.approved';

    public const BUDGET_REJECTED = 'budget.rejected';

    public const BUDGET_REOPENED = 'budget.reopened';

    public const BUDGET_VERSION_CREATED = 'budget.version_created';

    // Fase 4D — relatório do orçamento (os dados saem do sistema: formato, partes e versão; o arquivo não é guardado)
    public const BUDGET_REPORT_EXPORTED = 'budget.report_exported';

    // Fase 4C — catálogo de insumos (CRUD em registry.*, entity_type resource)
    public const RESOURCE_IMPORTED = 'resource.imported';

    // Calendário e horários da obra (CRUD de exceções em registry.*, entity_type project_calendar_exception)
    public const CALENDAR_IMPORTED = 'calendar.imported';

    public const SCHEDULE_UPDATED = 'calendar.schedule_updated';

    public const SCHEDULE_RESET = 'calendar.schedule_reset';

    // Fase 5A — contratos (CRUD em registry.*, entity_type contract, contract_item, contract_addendum…)
    public const CONTRACT_STATUS_CHANGED = 'contract.status_changed';

    public const CONTRACT_ITEMS_GENERATED = 'contract.items_generated';

    public const CONTRACT_ADDENDUM_APPROVED = 'contract.addendum_approved';

    public const CONTRACT_ADDENDUM_CANCELED = 'contract.addendum_canceled';

    // Fase 6A — planejamento (atividades e dependências em registry.*, entity_type schedule_task, schedule_dependency)
    public const SCHEDULE_CREATED = 'schedule.created';

    public const SCHEDULE_DELETED = 'schedule.deleted';

    public const SCHEDULE_BASELINE_CREATED = 'schedule.baseline_created';

    // 6B — custos trazidos do orçamento para as atividades (D-P93); os recursos em si auditam em registry.*
    public const SCHEDULE_COSTS_IMPORTED = 'schedule.costs_imported';

    /** @return list<string> */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }

    /** Eventos da Fase 2 (cobertura verificada pelos testes do módulo de cadastros). @return list<string> */
    public static function phase2(): array
    {
        return array_values(array_filter(self::all(), fn (string $e) => str_starts_with($e, 'registry.') || str_starts_with($e, 'document.')));
    }

    /** Eventos próprios da Fase 3 (cobertura verificada pelos testes de obras). @return list<string> */
    public static function phase3(): array
    {
        return array_values(array_filter(self::all(), fn (string $e) => str_starts_with($e, 'project.')));
    }

    /** Eventos da Fase 4 (cobertura verificada pelos testes de referências/orçamentos). @return list<string> */
    public static function phase4(): array
    {
        return array_values(array_filter(self::all(), fn (string $e) => str_starts_with($e, 'reference.') || str_starts_with($e, 'budget.') || str_starts_with($e, 'resource.')));
    }

    /** Eventos da Fase 5 (cobertura verificada pelos testes de contratos). @return list<string> */
    public static function phase5(): array
    {
        return array_values(array_filter(self::all(), fn (string $e) => str_starts_with($e, 'contract.')));
    }

    /** Eventos da Fase 6 (cobertura verificada pelos testes do planejamento). @return list<string> */
    public static function phase6(): array
    {
        return array_values(array_filter(self::all(), fn (string $e) => str_starts_with($e, 'schedule.')));
    }

    /** Eventos do calendário e dos horários da obra (cobertura verificada pelos testes do calendário). @return list<string> */
    public static function calendar(): array
    {
        return array_values(array_filter(self::all(), fn (string $e) => str_starts_with($e, 'calendar.')));
    }
}
