# API.md

Base: `/api/v1` · JSON · UTF-8. Todas as rotas são versionadas.

## Convenções

| Item | Regra |
|---|---|
| Versionamento | Prefixo `/api/v1`; mudanças incompatíveis → `/api/v2` |
| Autenticação | Sanctum (cookie SPA ou token Bearer) — *a implementar* |
| Tenant | **Nunca** informado pelo cliente; vem do contexto autenticado |
| Correlação | Header `X-Request-Id` (aceito se `^[A-Za-z0-9\-_]{8,64}$`, senão gerado); sempre devolvido |
| Datas | ISO 8601 UTC |
| Valores monetários | string/decimal com 2 casas na serialização (a definir com o módulo financeiro) |
| Paginação | a definir (`page`, `per_page`, metadados) |

## Formato padrão de erro

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Os dados informados são inválidos.",
    "details": { "email": ["Obrigatório."] },
    "request_id": "64ab0f9e-f096-4a78-b405-81241b633a58"
  }
}
```

`details` só existe em validação (422). Em 5xx com `APP_DEBUG=true` há `debug`; **nunca** em produção.

| HTTP | `code` |
|---|---|
| 400 | `bad_request` |
| 401 | `unauthenticated` |
| 403 | `forbidden` |
| 404 | `not_found` (inclui "pertence a outro tenant") |
| 405 | `method_not_allowed` |
| 409 | `conflict` |
| 403 | `tenant_required` (sem tenant ativo / token sem `tenant_id` em rota de negócio) · `tenant_forbidden` (sem vínculo ativo) · `tenant_suspended` · `tenant_fixed` (token não troca de tenant) · `account_blocked` · `stateful_required` · `privilege_escalation` |
| 409 | `last_admin` · `already_member` · `membership_suspended` · `invitation_pending` · `role_in_use` · `system_role_immutable` · `invalid_membership_state` |
| 410 | `invitation_invalid` · `invitation_expired` |
| 409 | `tenant_mismatch` (se o `X-Tenant-Hint` for aprovado: tenant exibido ≠ tenant da sessão; **sem efeito colateral**) |
| 419 | `session_expired` |
| 422 | `validation_failed` |
| 429 | `too_many_requests` |
| 500 | `server_error` |
| 503 | `service_unavailable` |

## Endpoints atuais

### `GET /api/v1/health` — liveness
Sem dependências. `200`:
```json
{ "status": "ok", "service": "obraflow-api", "version": "v1", "time": "2026-11-12T17:09:04+00:00" }
```

### `GET /api/v1/health/ready` — readiness
Verifica MySQL e Redis. `200` se tudo OK, **`503`** se algum falhar:
```json
{ "status": "degraded", "checks": { "database": "fail", "redis": "fail" }, "time": "..." }
```

### `GET /sanctum/csrf-cookie`
Fornecido pelo Sanctum para o fluxo SPA.

## Endpoints previstos (não implementados)

**Fase 1: implementada** (tabela abaixo). Rotas `auth/*` e `session/tenant` aceitam usuário autenticado **sem** tenant ativo (inclusive token sem `tenant_id`); **todas as demais** (grupo `tenant.required`) exigem tenant ativo validado. `X-Tenant` nunca é lido como autoridade; `X-Tenant-Hint` é só comparado (`409 tenant_mismatch`). Detalhes: [AUTENTICACAO.md](AUTENTICACAO.md).

| Método e rota | Permissão | Descrição |
|---|---|---|
| `POST /auth/login` | pública (SPA, throttle 5/min) | Login por sessão; 1 vínculo ativo ⇒ seleciona o tenant |
| `POST /auth/forgot-password` · `POST /auth/reset-password` | pública (throttle 3/min) | Recuperação; resposta igual exista ou não a conta |
| `POST /auth/logout` · `GET /auth/me` · `GET /auth/tenants` · `POST /auth/logout-others` | autenticado | Sessão/token; vínculos ativos; encerrar outras sessões |
| `POST /session/tenant` | autenticado (sessão) | Seleção/troca explícita do tenant (audita `tenant.selected`) |
| `POST /invitations/accept` | pública (throttle) | Aceite de convite (o token do e-mail é a credencial) |
| `GET /tenant` | `tenant.view` | Tenant ativo e papéis do usuário |
| `GET /tenant/settings` · `PUT /tenant/settings/{key}` | `settings.view` · `settings.manage` | Configurações do tenant (dado de negócio de exemplo) |
| `GET /tokens` · `POST /tokens` · `DELETE /tokens/{id}` | `tokens.manage` | Tokens de API do tenant ativo. Corpo: `name`, `expires_in_days` (1–90, obrigatório) e `abilities` (obrigatórias, sem curinga, sem gestão de acesso) |
| `GET /members` | `members.view` | Membros e papéis |
| `POST /members/{id}/suspend` · `…/reactivate` · `DELETE /members/{id}` | `members.manage` | Ciclo de vida do vínculo (último admin protegido) |
| `POST /members/{id}/roles` · `DELETE /members/{id}/roles/{roleId}` | `roles.manage` | Atribuir/retirar papel |
| `GET /roles` · `GET /roles/{id}` · `GET /permissions` | `roles.view` | Papéis do tenant e catálogo global |
| `POST /roles` · `PUT /roles/{id}` · `DELETE /roles/{id}` | `roles.manage` | Papéis do tenant (os de sistema são imutáveis) |
| `GET /invitations` | `members.view` | Convites do tenant |
| `POST /invitations` · `DELETE /invitations/{id}` | `members.invite` | Criar (o token só vai por e-mail) / revogar |
| `GET /audit-logs` | `audit.view` | Trilha do tenant ativo (somente leitura; filtros `event`, `entity_type`, `user_id`) |

**Fases seguintes:** empresas, clientes, fornecedores, obras, orçamentos (versões, itens, composições, aprovação), contratos, medições, financeiro, compras, estoque, documentos. Cada módulo documenta seus recursos aqui ao ser implementado.
