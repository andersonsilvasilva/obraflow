# MULTITENANCY.md — ISOLAMENTO MULTI-TENANT

> **Implementado na Fase 1** (D-P01 aprovada). Colunas, FKs e catálogo de auditoria: [FASE1_MODELO.md](FASE1_MODELO.md). Fluxos: [AUTENTICACAO.md](AUTENTICACAO.md). Evidência: [TESTES.md](TESTES.md). Detalhes de implementação no §9.

## 1. Modelo

- **Banco compartilhado, isolamento lógico** por `tenant_id` (D-01).
- **Tenant** = organização contratante do SaaS. Pode ter várias **empresas** e **filiais** (fases futuras).
- **`users` é global**: o mesmo login atende várias construtoras. A relação é `tenant_users` (`UNIQUE (tenant_id, user_id)`, `membership_status ∈ active | suspended | removed`).
- Acesso a dados = **tenant ativo ∩ vínculo ativo ∩ empresa ∩ obra ∩ permissão**.

```text
usuário ─(tenant_users ativo)→ tenant ─(user_companies)→ empresa ─(user_project_access)→ obra ─(Spatie: papel no tenant)→ ação
```

Todas as camadas precisam permitir; qualquer uma pode negar.

## 2. Regras invioláveis

1. **Nunca confiar em `tenant_id` vindo do frontend** (corpo, query, header, rota).
2. O tenant vem **apenas** da sessão do servidor (SPA) ou do `tenant_id` do token (API).
3. **Toda requisição de negócio revalida** o vínculo ativo do usuário com o tenant.
4. Toda consulta a entidade operacional é escopada por tenant; sem contexto, **falha fechada** (exceção, nunca "retorna tudo").
5. Relações entre registros validadas quanto ao tenant (banco + aplicação).
6. "Não encontrado" e "pertence a outro tenant" respondem **igual** (404) — evita enumeração. *(já no renderizador de erros)*
7. **Sem papéis globais** no MVP: todo papel pertence a um tenant.
8. **Token sem `tenant_id` nunca acessa dados de negócio.**
9. Sem `withoutGlobalScopes()` fora de código de plataforma revisado.

## 3. Resolução do tenant ativo — **D-P01 (aprovada)**

| Canal | Fonte do tenant | Como se define |
|---|---|---|
| **SPA** (cookie Sanctum) | **Sessão no servidor** (Redis) | Seleção **explícita**: `POST /api/v1/session/tenant`. Um único vínculo ⇒ seleção automática |
| **API / integrações** | **`personal_access_tokens.tenant_id`** | Definido na **emissão** pelo tenant ativo do emissor; **imutável** |
| Subdomínio | — | **Futuro**, só **pré-seleção**; sempre revalidado contra o vínculo. `tenant_domains` já existe, sem uso |
| Header `X-Tenant` | — | **Não é autoridade** e não será usado para resolver o tenant |

### Validação em toda requisição (middleware `SetTenantContext`)

1. Resolve o tenant (sessão ou token). Ausente ⇒ `403 tenant_required`.
2. Confere `tenant_users` ativo para `(tenant_id, user_id)`. Falha ⇒ `403 tenant_forbidden`.
3. `tenants.status` e `users.status` permitem acesso (senão `403 tenant_suspended` / `403 forbidden`).
4. Registra `TenantContext` e chama `setPermissionsTeamId()` (Spatie).
5. **Sem cache** dessa checagem na Fase 1: a suspensão/remoção do vínculo vale na próxima requisição.

### Troca de tenant

Valida o **destino** (itens 2–3), grava na sessão, **regenera o ID da sessão**, atualiza `tenant_users.last_used_at` e audita `tenant.selected` (origem → destino). Tentativa negada audita `tenant.selection_denied`.

### Limitação: contexto compartilhado entre abas

A sessão guarda **um tenant por navegador**. Trocar de tenant na aba B faz a aba A — ainda exibindo o tenant anterior — operar no **novo** tenant na próxima requisição, sem sinal visual. O isolamento **não** é quebrado (o servidor sempre usa o tenant da sessão e autoriza), mas a interface fica inconsistente. Mitigações propostas, **sujeitas a aprovação** ([FASE1_MODELO.md](FASE1_MODELO.md) §4 e §9-A10):

1. `X-Tenant-Hint` com o tenant exibido: **apenas comparação** — divergência ⇒ `409 tenant_mismatch` **sem executar** a operação.
2. `BroadcastChannel` para sincronizar abas e recarregar após a troca.
3. Aviso na interface; uso paralelo de vários tenants por **tokens**.

## 4. Camadas de defesa

| Camada | Mecanismo | Pega que erro |
|---|---|---|
| Banco | `tenant_id NOT NULL`, FKs compostas, `UNIQUE (tenant_id, …)` | Inserir/atualizar com referência cruzada |
| Banco (Spatie) | `model_has_roles` com FK composta para `roles(tenant_id, id)` **e** para `tenant_users(tenant_id, user_id)` | Papel de outro tenant; usuário sem vínculo |
| Eloquent | Trait `BelongsToTenant` (escopo global, preenche no `creating`, proíbe alterar `tenant_id`); `tenant_id` fora de `$fillable` | Consultas esquecidas de filtro |
| Validação | Regras `exists/unique` **escopadas ao tenant** em todo Form Request | ID de outro tenant enviado pelo cliente |
| Autorização | Policies (tenant, empresa, obra) + Spatie por tenant | Acesso horizontal |
| Rotas | Binding resolve **dentro do escopo**; grupo `tenant.required` padrão | Enumeração por ID/uuid |
| Filas | **A implementar com o primeiro job** (a Fase 1 não tem jobs; fila `sync`): o job carregará `tenant_id` e rodará dentro de `TenantContext::run()`, que restaura contexto Spatie e relações | Job sem tenant ou com tenant errado |
| Cache/Redis | Chaves **sempre** prefixadas por tenant (`t:{id}:…`). **Exceção conhecida:** o cache de permissões do Spatie é **global** (um registro com permissões e papéis de todos os tenants); o filtro por tenant acontece na consulta (`HasRoles`) | Vazamento via cache — coberto por teste cross-tenant após troca de tenant |
| Storage | Chave `tenants/{id}/…`; URL assinada de curta duração | Acesso direto a arquivo |
| Auditoria | Eventos de acesso na **mesma transação**; `tenant_id` + `request_id` | Rastreabilidade |

## 5. Padrões de código (a implementar após aprovação)

```php
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', fn ($q) => $q->where(
            $q->getModel()->getTable().'.tenant_id',
            app(TenantContext::class)->idOrFail()   // lança se não houver contexto
        ));

        static::creating(function ($model) {
            $model->tenant_id = app(TenantContext::class)->idOrFail(); // ignora valor do cliente
        });

        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('tenant_id é imutável.');
            }
        });
    }
}
```

`AppServiceProvider` deve chamar `Relation::enforceMorphMap(['user' => User::class])` (necessário para o `CHECK (model_type = 'user')`), e o modelo `User` deve fixar `$guard_name = 'web'` para que sessão e token usem as mesmas permissões.

## 6. Spatie Laravel Permission com Teams

Configuração **já aplicada** em [`obraflow-api/config/permission.php`](../obraflow-api/config/permission.php): `teams = true`, `team_foreign_key = tenant_id`. Pacote instalado: **spatie/laravel-permission 8.5.0**.

### O que o pacote faz (verificado no código instalado)

- A relação `roles()` do usuário filtra o **pivô** por `tenant_id = tenant atual` **e** exige `roles.tenant_id IS NULL OR = tenant atual`.
- `Role::create()` sem `tenant_id` explícito usa o tenant do contexto; **sem contexto grava `NULL`**.
- O cache de permissões (24 h por padrão) é um **único** registro global.
- A migration padrão cria `roles.tenant_id` **nullable**, `model_has_roles.role_id` com FK **simples** e `ON DELETE CASCADE`, e **não** cria FK para `tenants`.

### O que o ObraFlow muda (migration própria, baseada no stub)

| Ponto | Pacote | ObraFlow |
|---|---|---|
| `roles.tenant_id` | NULL | **NOT NULL** + FK `tenants` (sem papéis globais; `Role::create` sem contexto **falha** no banco) |
| Unicidade de papéis | `(tenant_id, name, guard_name)` com `NULL` | Mesma chave, agora **sem NULL** ⇒ exata |
| `model_has_roles` → papel | FK simples `role_id` (CASCADE) | **FK composta `(tenant_id, role_id) → roles(tenant_id, id)`**, RESTRICT: o papel pertence ao mesmo tenant do vínculo |
| `model_has_roles` → usuário | nenhuma | **FK composta `(tenant_id, model_id) → tenant_users(tenant_id, user_id)`** + `CHECK (model_type = 'user')` |
| `model_has_permissions` | FK simples | mesma proteção de usuário; **permissões diretas não são usadas** (só por papel) |
| `role_has_permissions` | CASCADE | **RESTRICT** (desvínculo explícito e auditado) |

### Procedimento obrigatório

1. **Antes** de qualquer `can()/hasRole()/assignRole()`, definir o contexto: `app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId)`.
2. Fazer isso no `SetTenantContext` (HTTP) **e** em cada job/comando.
3. Ao trocar de tenant no mesmo processo, chamar de novo e limpar relações em cache do usuário (`unsetRelation('roles')`, `unsetRelation('permissions')`).
4. Papéis de sistema (`is_system = 1`) são criados **no provisionamento do tenant**; não podem ser excluídos.
5. `permissions` é catálogo global (instalado por comando idempotente); a atribuição é por papel dentro do tenant.
6. Falhas de FK composta (`SQLSTATE 23000`) viram `404/422` na borda — nunca `500`.

## 7. Testes obrigatórios (por módulo e na Fase 1)

**Isolamento**
- Acesso cruzado: usuário do tenant A tenta ler/alterar/excluir registro do tenant B → `404`.
- ID injetado (`project_id`/`customer_id` de outro tenant) → `422`; se burlado, erro de FK.
- `tenant_id` forjado em payload/header/query → ignorado.
- Sem contexto de tenant, modelo com `BelongsToTenant` **lança exceção**.

**Tenant ativo / vínculo**
- Usuário em dois tenants enxerga somente o tenant ativo; trocar de tenant troca papéis e permissões.
- Vínculo `suspended`/`removed` ⇒ `403` na próxima requisição; tokens daquele tenant revogados.
- Tenant `suspended` ⇒ `403`.
- Troca de tenant regenera a sessão e grava `tenant.selected`.
- (Se aprovado) `X-Tenant-Hint` divergente ⇒ `409` **sem efeito colateral**.

**Tokens**
- Token com `tenant_id` acessa **só** seu tenant; token sem `tenant_id` ⇒ `403 tenant_required` em qualquer rota de negócio.
- Token de usuário suspenso/removido deixa de funcionar imediatamente.
- Impossível emitir token para tenant sem vínculo (FK composta).

**Spatie**
- Mesma pessoa *admin* no tenant A e *leitora* no B.
- Atribuir papel do tenant B no vínculo do tenant A ⇒ **recusado pelo banco**.
- Atribuir papel a usuário sem vínculo ⇒ **recusado pelo banco**.
- Não existe papel com `tenant_id NULL`; `Role::create` sem contexto falha.
- Funciona igual com cookie (`web`) e com token (`sanctum`).
- Permissões corretas dentro de um job.

**Auditoria**
- Cada evento do catálogo ([FASE1_MODELO.md](FASE1_MODELO.md) §6) é gravado com ator, tenant e `request_id`.
- Segredos **nunca** aparecem (senha, token, hash, `remember_token`).
- Falha na gravação reverte a operação de autorização.
- `audit_logs` não aceita `update/delete` pelo modelo.

## 9. Implementação (Fase 1)

| Peça | Arquivo |
|---|---|
| Contexto (escopado por requisição; `use/forget/run`; sincroniza o Spatie e descarta relações em cache) | `app/Modules/Tenancy/TenantContext.php` |
| Escopo global, `creating` e imutabilidade de `tenant_id` | `app/Modules/Tenancy/Concerns/BelongsToTenant.php` (usado em `TenantSetting`, `TenantDomain`, `UserInvitation`; **não** em `Role` — D-24) |
| Resolução e validação do tenant ativo | `ActiveTenantResolver.php` |
| Seleção/troca de tenant | `TenantSelector.php` |
| Middlewares | `SetTenantContext` (alias `tenant.context`), `EnsureUserActive` (`user.active`); grupos `authenticated` e `tenant.required` em `bootstrap/app.php` |
| Morph map obrigatório + modelo de token | `app/Providers/AppServiceProvider.php` |

Notas operacionais:
- **Rota nova de negócio entra em `routes/api/v1/tenant.php`, dentro de `Route::middleware('tenant.required')`.** Fora do grupo não há tenant.
- O `TenantContext` é `scoped`; é limpo no `terminate()` do middleware. Em servidores de processo longo (Octane) é preciso descarregar também os controllers entre requisições (o Octane faz) — controllers que recebem `TenantContext` no construtor reteriam o da requisição anterior. Em PHP-FPM não há risco.
- Ao trocar o tenant dentro do mesmo processo (jobs, comandos, testes) use **sempre** `TenantContext::use()/run()`, nunca `setPermissionsTeamId()` direto.
- Convites e provisionamento ativam o contexto do tenant-alvo por `TenantContext::run()`.

## 8. Plataforma (superadmin)

**Fora da Fase 1** (operações de plataforma só por CLI: [PROVISIONAMENTO.md](PROVISIONAMENTO.md)). Quando existir, usará guard e rotas separados, **explicitamente** fora do escopo de tenant, com auditoria reforçada (`audit_logs.tenant_id NULL` já é permitido para eventos fora de tenant). Sem papéis globais do Spatie para isso.
