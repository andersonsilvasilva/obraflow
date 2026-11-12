# ARCHITECTURE.md

## 1. Visão

Aplicação em duas camadas desacopladas, servidas atrás de um Nginx:

```text
Navegador ──► Nginx ──┬─ /        → obraflow-web  (SPA React, estática)
                      └─ /api/*   → obraflow-api  (Laravel, PHP-FPM)
                                        ├─ MySQL  (dados)
                                        └─ Redis  (cache, filas, sessão)
```

Futuro: Object Storage S3 (documentos), workers de fila, IA via provedor externo (com aprovação humana).

## 2. Backend (`obraflow-api`)

| Item | Escolha |
|---|---|
| Framework | Laravel **13.x**, PHP **8.4** |
| Banco | MySQL (alvo 8.4 LTS) |
| Cache/fila/sessão | Local: `file`/`sync`/`file` (**Redis adiado**, ver [AMBIENTE_LOCAL.md](AMBIENTE_LOCAL.md) §4). Containers: Redis via **Predis** (a extensão `phpredis` não está instalada no ambiente local) |
| Autenticação | Laravel **Sanctum** (SPA por cookie + tokens por tenant) |
| Autorização | **Spatie Permission** com **Teams** (`team_foreign_key = tenant_id`) |
| Testes | **Pest 4** |
| Estilo | Laravel Pint |

### Estrutura modular

```text
app/
├── Enums/             # MembershipStatus, UserStatus, TenantStatus, InvitationStatus
├── Models/            # User (global), Tenant, TenantUser, TenantDomain, TenantSetting, UserInvitation,
│                      # AuditLog (append-only), PersonalAccessToken (Sanctum + tenant), Role, Permission (Spatie)
├── Http/Controllers/Controller.php
└── Modules/
    ├── Shared/        # erros padronizados (ApiException/ApiExceptionRenderer), middleware RequestId
    ├── Core/          # health check (Database; Redis só se algum driver o usar)
    ├── Tenancy/       # TenantContext, BelongsToTenant, ActiveTenantResolver, TenantSelector,
    │                  # middlewares SetTenantContext / EnsureUserActive, TenantController, TenantSettingController
    ├── Auth/          # login/logout/me, recuperação de senha, tokens (IssueApiToken), seleção de tenant, MePayload
    ├── Access/        # MembershipService, RoleService, InvitationService, ProvisionTenant, PrivilegeGuard,
    │                  # LastAdminGuard, TenantLock, PermissionCatalog + comandos Artisan (obraflow:*)
    └── Audit/         # AuditLogger, AuditSanitizer, AuditEvent (catálogo), AuditLogController
routes/
├── api.php            # prefixo /api/v1, carrega routes/api/v1/*.php
└── api/v1/            # core.php (health) · auth.php (grupo `authenticated`) · tenant.php (grupo `tenant.required`)
```

Grupos de middleware (`bootstrap/app.php`): `authenticated` = `auth:sanctum` + `user.active`; `tenant.required` = `authenticated` + `tenant.context`. **Toda rota de negócio nova entra em `tenant.required`.**

Cada novo módulo vive em `app/Modules/<Nome>/` (Models, Policies, Services/Actions, Requests, Resources, Http/Controllers) e registra rotas em `routes/api/v1/<nome>.php`. Camadas: Controller (HTTP) → Form Request (validação) → Service/Action (caso de uso) → Model (persistência) → Policy (autorização) → API Resource (serialização).

### API

- Versionada em **`/api/v1`**. Detalhes em [API.md](API.md).
- Erros padronizados em um único formato JSON com `request_id` (ver API.md).
- Health: `GET /api/v1/health` (liveness) e `GET /api/v1/health/ready` (MySQL + Redis).

## 3. Frontend (`obraflow-web`)

| Item | Escolha |
|---|---|
| Build | Vite 8, React 19, TypeScript (strict) |
| Estilo | Tailwind CSS 4 (tokens em `src/index.css`), shadcn/ui (base) |
| Roteamento | React Router 7 |
| Dados remotos | TanStack Query 5 |
| Formulários | React Hook Form + Zod |
| Gráficos | Recharts |
| Ícones | Lucide |

```text
src/
├── app/            layouts/, providers/, router/, navigation.ts
├── components/     ui/ (shadcn), layout/, data-display/, forms/, feedback/
├── features/       dashboard, projects, budgets, procurement, parties, modules
├── lib/            env.ts (Zod), http.ts, api-error.ts, utils.ts
├── services/       camada de acesso a dados (mock ⇄ HTTP em um só ponto)
├── mocks/          dados demonstrativos centralizados
├── types/          interfaces TypeScript
└── utils/          format.ts (moeda, data, percentual pt-BR)
```

**Camada de serviços:** `services/api.ts → fetchResource(path, mock)` alterna entre dados demonstrativos (`VITE_USE_MOCKS=true`) e HTTP real. Nenhum dado fictício fica dentro de componentes.

**Erros:** `ApiError` espelha o formato do backend; `QueryBoundary` centraliza loading/erro/retry; `ErrorBoundary` protege a renderização; toasts para feedback de ações.

## 4. Infraestrutura

`infrastructure/docker-compose.yml`: `api` (PHP-FPM), `web` (Nginx estático), `mysql:8.4`, `redis:7`, `nginx` (borda, único serviço com porta publicada). Volumes persistentes (`mysql_data`, `redis_data`), *healthchecks* em todos os serviços, segredos **somente** por variáveis de ambiente obrigatórias (sem valores padrão). CI em `.github/workflows/ci.yml`.

## 5. Multi-tenancy e dados

Ver [MULTITENANCY.md](MULTITENANCY.md) e [DATABASE.md](DATABASE.md). Resumo: banco compartilhado, `tenant_id` em toda tabela operacional, FKs compostas, `users` global.

## 6. Decisões

Registro em [DECISIONS.md](DECISIONS.md) (gerais) e [DB_DECISIONS.md](DB_DECISIONS.md) (dados).
