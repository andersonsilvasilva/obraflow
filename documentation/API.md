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

## Fase 2 — Cadastros (implementado)

Todas as rotas exigem sessão/token **com tenant ativo** (`tenant.required`) e a permissão indicada. Detalhes e regras: [FASE2_MODELO.md](FASE2_MODELO.md) §Etapa 4.

| Recurso | Rotas | Permissão |
|---|---|---|
| Unidades | `GET/POST /units`, `PUT /units/{id}` | `units.view` / `units.manage` |
| Empresas | `GET/POST /companies`, `GET/PUT/DELETE /companies/{uuid}` | `companies.view` / `companies.manage` |
| Filiais | `GET/POST /companies/{uuid}/branches`, `GET/PUT/DELETE /branches/{id}` | `companies.*` |
| Clientes / Fornecedores | `GET/POST /customers`, `GET/PUT/DELETE /customers/{uuid}` (idem `/suppliers`) | `customers.*` / `suppliers.*` |
| Endereços / Contatos | `GET/POST /{companies\|branches\|customers\|suppliers}/{owner}/{addresses\|contacts}`, `PUT/DELETE …/{id}` | do módulo do dono |
| Categorias de documento | `GET/POST /document-categories`, `PUT /document-categories/{id}` | `documents.view` / `documents.manage` |
| Documentos | `GET/POST /documents`, `GET/PUT /documents/{uuid}`, `POST /documents/{uuid}/versions`, `POST /documents/{uuid}/versions/{n}/url`, `POST/DELETE /documents/{uuid}/links[/{id}]`, `PUT /documents/{uuid}/permissions` | `documents.view` + acesso por documento |

Listagens: `?q=` (busca; números também no documento), `?status=`, `?per_page=` (máx. 100); resposta `{data, meta}`. Documentos: upload multipart (`file`, tipo verificado pelo conteúdo: PDF, PNG, JPEG, WebP, DOCX, XLSX; limite `DOCUMENTS_MAX_KB`). O download é por **URL assinada** (5 min) em `/files/documents/…`; a chave de armazenamento nunca é exposta.

## Fase 3 — Equipes e Obras (implementado)

Todas as rotas em `tenant.required`; entidades por uuid (funcionários, equipamentos, obras), catálogos e detalhes por id. Mesmo formato de lista (`{data, meta}`, `?q=`, `?per_page=`) e de erro. Regra de negócio violada → **422 `domain_rule`**; CHECK violado que escape da validação → **422 `constraint_violation`**; duplicidade → 409 `duplicate`; em uso → 409 `in_use`.

| Recurso | Rotas | Permissão |
|---|---|---|
| Cargos / Setores | `GET/POST /job-titles`, `PUT/DELETE /job-titles/{id}`; idem `/departments` | `employees.view` / `employees.manage` |
| Funcionários | `GET/POST /employees`, `GET/PUT/DELETE /employees/{uuid}` (filtros `company_uuid`, `job_title_id`, `department_id`, `labor_type`, `contract_type`, `status`) | `employees.view` / `employees.manage` |
| Certificações | `GET/POST /employees/{uuid}/certifications`, `PUT/DELETE …/{id}` (`document_uuid` opcional) | idem |
| Grupos de recursos | `GET/POST /resource-groups`, `PUT/DELETE /resource-groups/{id}` (só `equipment` nesta fase; 2 níveis) | `equipment.view` / `equipment.manage` |
| Equipamentos | `GET/POST /equipment`, `GET/PUT/DELETE /equipment/{uuid}` (filtros `company_uuid`, `group_id`, `unit_id`, `ownership`, `status`) | `equipment.view` / `equipment.manage` |
| Obras | `GET/POST /projects`, `GET/PUT/DELETE /projects/{uuid}` (filtros `status`, `company_uuid`, `customer_uuid`) | `projects.view` + nível na obra; criar/excluir `projects.manage` |
| Status da obra | `POST /projects/{uuid}/status` `{status, reason?}`, `GET …/status-history` | nível `edit` / `view` |
| Endereço, fases, equipe | `GET/POST /projects/{uuid}/addresses`, `PUT/DELETE …/{id}`; `POST /projects/{uuid}/phases`, `PUT/DELETE …/{id}`; `POST /projects/{uuid}/members`, `PUT/DELETE …/{id}` | leitura `view`, escrita `edit` |
| Acesso por obra | `GET/POST /projects/{uuid}/access` `{user_uuid, access_level}`, `DELETE …/access/{userUuid}` | nível `manage` |
| Documentos | `POST /documents/{uuid}/links` aceita `linkable_type` `employee`, `equipment`, `project` (chave = uuid) | além do documento: `employees.view` / `equipment.view` / nível `edit` na obra |

**Regras de visibilidade:**
- **Obras (D-P24):** quem tem `projects.manage` vê todas. Os demais veem só as obras liberadas, e o nível (`view` < `edit` < `manage`) limita a ação. Obra sem acesso → **404**.
- **Tokens (D-29):** sem `projects.manage` no token, vale o acesso por obra até para o admin.
- **Nível na resposta:** cada obra traz `access_level`, o nível de quem consulta, para o frontend habilitar ações.
- **CPF (D-P21):** CPF do funcionário completo só com `employees.manage`; os demais recebem `***.982.247-**`.
- **Status da obra (D-P27):** não é aceito no `PUT`. As transições permitidas estão em `Project::TRANSITIONS`.

## Fase 4A — Referências técnicas (implementado)

Dados **globais e somente leitura** (D-P29), iguais para todos os tenants. As rotas ficam em `tenant.required` e exigem `references.view`. Não há rota de escrita: as bases entram só pelo comando `obraflow:references:import` (D-P30).

| Rota | Retorno |
|---|---|
| `GET /references/datasets` | Bases ativas: fonte, competência, UF, localidade, regime de encargos e contagens. Filtros `state`, `charges_regime`, `include_superseded=1` |
| `GET /references/datasets/{id}/resources` | Insumos paginados: `q` (código por prefixo ou palavras da descrição) e `kind` (`material`, `labor`, `equipment`, `service`, `none`). Cada insumo traz preço e origem do preço publicados |
| `GET /references/datasets/{id}/resources/{code}` | Insumo e a base de origem |
| `GET /references/datasets/{id}/compositions` | Composições paginadas: `q`, `class_code` |
| `GET /references/datasets/{id}/compositions/{code}` | Composição com decomposição de custos, classe, tipo, agrupador e itens: insumo **ou** composição filha, coeficiente com 7 casas, preço unitário, custo e origem |

Base em importação ou com falha → 404. Base substituída continua consultável pelo `id`, para manter rastreáveis os orçamentos antigos.
