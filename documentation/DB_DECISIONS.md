# DB_DECISIONS.md — DECISÕES DE MODELAGEM

Cada decisão: contexto → decisão → alternativas avaliadas → consequências. `D-P*` = **decisão pendente** (precisa de resposta humana).

## Decisões tomadas (propostas)

### D-01 — Banco compartilhado, isolamento lógico por `tenant_id`
- **Decisão:** um único banco/schema; `tenant_id NOT NULL` em toda tabela operacional.
- **Alternativas:** (a) banco por tenant — melhor isolamento e restore individual, mas custo operacional alto e migrations N×; (b) schema por tenant — idem. 
- **Consequência:** isolamento depende de constraints + escopo global + testes (ver [MULTITENANCY.md](MULTITENANCY.md)). Mantém a porta aberta para migrar tenants grandes para banco dedicado (todas as chaves já carregam `tenant_id`).

### D-02 — `users` é global (sem `tenant_id`)
- **Decisão:** identidade única por e-mail; vínculo via `tenant_users`.
- **Alternativa rejeitada:** `users.tenant_id` (rascunho anterior de DATABASE.md) — obriga um login por construtora e impede consultores/engenheiros atuarem em vários clientes.
- **Consequência:** `email` único global; colunas `*_by → users.id` não podem ter FK composta; "usuário pertence ao tenant" é verificado em `tenant_users` (aplicação + validação). Permissões por tenant via Spatie Teams (D-04). **Substitui** o DATABASE.md antigo e o campo `users.tenant_id` ali descrito.

### D-03 — Chaves estrangeiras compostas `(tenant_id, id)`
- **Decisão:** `UNIQUE (tenant_id, id)` nas tabelas-pai e FKs compostas nos filhos.
- **Alternativa:** FK simples + validação em aplicação — mais simples, porém um bug de aplicação cruza dados entre clientes sem o banco impedir.
- **Consequência:** +1 índice por pai e por FK; Eloquent inalterado (ver [DB_CONSTRAINTS.md](DB_CONSTRAINTS.md) §1). Exceções documentadas (polimórficas, globais, snapshots).

### D-04 — Spatie Permission com Teams = tenant
- **Decisão:** `teams=true`, `team_foreign_key='tenant_id'` (já aplicado em `obraflow-api/config/permission.php`). Contexto definido por `setPermissionsTeamId($tenantId)` no middleware, antes de qualquer checagem.
- **Alternativa rejeitada:** sistema próprio de permissões (vedado sem justificativa).
- **Riscos:** esquecer de definir o team em *jobs/queues/comandos* faz as checagens olharem o tenant errado/nenhum → o contexto é restaurado em cada job (middleware de job).

### D-05 — Token Sanctum vinculado ao tenant
- **Decisão (aprovada, D-P01):** `personal_access_tokens.tenant_id` fixa o tenant na emissão; o token só vale nele e **não** acessa dados de negócio sem `tenant_id` (D-20). SPA usa cookie + tenant ativo na **sessão do servidor**, com seleção explícita. Toda requisição **revalida** o vínculo ativo; a troca de tenant é **auditada** (`tenant.selected`).
- **Limitação documentada:** a sessão guarda um tenant por navegador; abas com tenants diferentes se afetam (mitigações em [MULTITENANCY.md](MULTITENANCY.md) §3).
- **Alternativa:** header `X-Tenant` confiável — rejeitada: o cliente nunca é fonte de verdade do tenant.

### D-06 — IDs: `BIGINT UNSIGNED` internos + `uuid CHAR(36)` públicos
- **Decisão:** PK sequencial (desempenho em joins/índices); `uuid` apenas onde há exposição externa/sincronização offline.
- **Alternativas:** UUID/ULID como PK (fragmenta índice InnoDB, 16–36 bytes em toda FK); `BINARY(16)` para uuid (economiza espaço, pior ergonomia no Laravel). `CHAR(36)` escolhido pela simplicidade; revisitar se houver pressão de tamanho.

### D-07 — Precisão decimal
- **Decisão:** dinheiro `(15,2)`; **preço unitário `(15,4)`** (ajuste ao padrão inicial `15,2`: tabelas de referência publicam insumos com mais de 2 casas e arredondar o unitário distorce o total); quantidade `(18,4)`; coeficiente `(18,6)`; percentual `(9,4)`.
- **Arredondamento:** cálculo com `bcmath`/Decimal na aplicação; **arredondar *por linha* (meio para cima, 2 casas)** o total da linha (`quantidade × preço unitário`) e somar linhas já arredondadas; totais agregados nunca são re-arredondados. Regra única e testada; mudar exige nova versão de orçamento.
- **Multi-moeda:** não implementada no MVP. `currency char(3)` existe apenas em `plans`, `invoices`, `financial_accounts` para evolução; sem conversão.

### D-08 — Orçamento aprovado é imutável e autossuficiente
- **Decisão:** `budgets → budget_versions → …`; itens e insumos **copiam** código, descrição, unidade, coeficiente e preço; origem registrada em `source`, `source_*_id` (sem FK) e `source_snapshot`. `locked_at` na versão aprovada torna os itens somente leitura (aplicação + trigger de defesa opcional). Nova revisão = nova versão (cópia).
- **Alternativa rejeitada:** itens referenciando diretamente `reference_*` — uma reimportação da base alteraria orçamentos aprovados retroativamente.
- **Consequência:** mais armazenamento (cópia), em troca de integridade histórica e auditoria.

### D-09 — Financeiro: título × movimentação
- **Decisão:** `accounts_payable/receivable` (obrigação/direito, com parcelas e vencimentos) separados de `financial_transactions` (fato realizado, **append-only**). Estorno = nova transação `reversal` com `reverses_id`. Parcelamento: uma linha por parcela, agrupadas por `installment_group`.
- **Alternativa:** tabela única com `paid_at` — mistura previsão com realizado e impede conciliação/estorno auditáveis.
- **Consequência:** saldo e fluxo de caixa são calculados a partir das transações; `cash_flow_entries` é cache recalculável.

### D-10 — Estoque por movimentações
- **Decisão:** `inventory_movements(+items)` é a fonte da verdade (append-only, quantidades assinadas); `inventory_balances` é projeção materializada **reconstruível** a partir dos movimentos, atualizada na mesma transação.
- **Alternativa rejeitada:** saldo editável sem histórico.

### D-11 — Empregado ≠ usuário
- **Decisão:** `employees` independente de `users` (`user_id` opcional). Dados pessoais sensíveis → LGPD (acesso restrito, mascaramento em logs).

### D-12 — Soft delete somente em cadastros mestres
- **Decisão:** `deleted_at` em `tenants, companies, company_branches, customers, suppliers, projects, employees, equipment`. Financeiro, contratos, medições, históricos e auditoria **não** têm soft delete: usam `status`, cancelamento e estorno.

### D-13 — Relações polimórficas controladas
- **Decisão:** `contacts`, `addresses`, `document_links`, `audit_logs` usam `*_type/*_id`; *morph map* com allowlist; sempre com `tenant_id`.
- **Risco:** sem FK, não há garantia do banco → validação em aplicação + testes de acesso cruzado obrigatórios.

### D-14 — Referências técnicas: global oficial × customizada por tenant
- **Decisão:** `reference_datasets.tenant_id NULL` = base oficial importada (compartilhada, somente leitura); preenchido = base própria do tenant. `price_origin ∈ {official, custom, negotiated}` em preços e itens de orçamento.
- **Regra:** nenhum valor oficial é digitado/inventado; só importação rastreável (`file_sha256`, `version`, competência, localidade, regime de encargos).
- **Consequência:** consultas de referência filtram `tenant_id IS NULL OR tenant_id = :tenant`.

### D-15 — Auditoria append-only
- **Decisão:** `audit_logs` sem `updated_at`; conta de aplicação sem UPDATE/DELETE; valores sensíveis mascarados antes de gravar; retenção/particionamento definidos antes de produção. Entidades com rastreabilidade completa: tenants, assinaturas, permissões/papéis, empresas, obras, contratos, orçamentos (todas as versões), medições, qualquer lançamento financeiro, documentos, sugestões de IA.
- **Distinção:** *dados atuais* (tabelas) × *histórico de transição* (`*_status_history`) × *versões* (`budget_versions`, `document_versions`) × *eventos de auditoria* (`audit_logs`). `updated_at` **não** é histórico.

### D-16 — Documentos em Object Storage
- **Decisão:** arquivos no S3 (ou compatível); banco guarda `storage_key`, `sha256`, `size_bytes`, `mime_type`, versão. Sem URL pública permanente; acesso por URL assinada de curta duração após checagem de `document_permissions`.

### D-17 — IA nunca decide
- **Decisão:** `ai_generated_suggestions` com `origin_model`, `user_id`, `tenant_id`, `status`; transição para `accepted` exige `reviewed_by`. Sugestão não escreve em orçamento/financeiro sozinha. Conteúdo enviado a provedores externos exige autorização e redação de dados (`redaction_applied`).

### D-18 — Sem papéis globais no MVP *(aprovada)*
- **Decisão:** todo papel pertence a um tenant (`roles.tenant_id NOT NULL`); `UNIQUE (tenant_id, name, guard_name)` sem `NULL`. Papéis de sistema (`is_system`) são criados por tenant no provisionamento. Administração de plataforma fica **fora** da Fase 1 e não usará papéis globais do Spatie.
- **Por quê:** `UNIQUE` aceita vários `NULL` no MySQL (papel global duplicável) e as consultas do Spatie tratam `NULL` como "global" em todo tenant. **Validado:** `Role::create` sem contexto falha fechado (erro 1048).

### D-19 — Integridade do Spatie Teams por FK composta *(aprovada)*
- **Decisão:** `model_has_roles`: FK `(tenant_id, role_id) → roles(tenant_id, id)` (papel do mesmo tenant do vínculo) e FK `(tenant_id, model_id) → tenant_users(tenant_id, user_id)` (usuário com vínculo), `CHECK (model_type = 'user')`, `RESTRICT`. Mesma proteção em `model_has_permissions`; permissões diretas **não** são usadas no MVP. Exige `Relation::enforceMorphMap(['user' => User::class])`.
- **Alternativas:** FK simples do stub (não impede papel de outro tenant); trigger (inviável sem SUPER, D-22); só validação em aplicação (frágil).
- **Consequência:** só `User` recebe papéis; outro tipo de modelo exigirá remover o `CHECK`/FK e reavaliar. **Validado** em MySQL 8.0.30 com Spatie 8.5.0 real.

### D-20 — Token só acessa negócio com `tenant_id` *(aprovada)*
- **Decisão:** token sem `tenant_id` acessa apenas `auth/*`; rotas de negócio exigem `tenant_id` no token **e** vínculo ativo, reavaliado a cada uso. FK composta impede token para tenant sem vínculo. Suspensão/remoção revoga os tokens do tenant.
- **Achado:** o Sanctum emite token sem tenant → modelo de token próprio ou ação transacional (R15).

### D-21 — Auditoria de acesso e semântica de falha *(aprovada; detalhes a confirmar)*
- **Decisão:** catálogo `dominio.acao` ([FASE1_MODELO.md](FASE1_MODELO.md) §6) cobrindo login, logout, seleção de tenant, convites, vínculos, papéis, permissões e tokens. Eventos de autorização são gravados **na mesma transação** (falha fecha a operação); `auth.*` em melhor esforço. `login_failed` guarda HMAC do e-mail.
- **Imutabilidade:** guarda no modelo + privilégios de produção (INSERT/SELECT). **Validado:** `UPDATE`/`DELETE` negados (1142).

### D-22 — Sem triggers na Fase 1
- **Decisão:** nenhuma trigger. **Validado:** com `log_bin=1` e `log_bin_trust_function_creators=0`, usuário sem SUPER não cria trigger (erro 1419). Regras que dependem de valor de coluna (ex.: vínculo `active`) ficam na aplicação, revalidadas a cada requisição.

### D-23 — Conexão MySQL em UTC
- **Decisão:** `timezone '+00:00'` na conexão (config de código) e `--default-time-zone=+00:00` nos containers. **Validado:** sessão em `-04:00` deslocou um `TIMESTAMP` em 4 h; em UTC o round-trip é exato.

### D-24 — `Role` sem escopo global de tenant *(implementada; achado da Etapa 3)*
- **Decisão:** o modelo `Role` **não** usa `BelongsToTenant`. O cache de permissões do Spatie é **um único registro global** que carrega a relação de papéis; um escopo por tenant faria o cache refletir só o primeiro tenant carregado e as checagens dos demais dariam negativo.
- **Compensação:** isolamento pelo banco (FKs compostas, `roles.tenant_id NOT NULL`), consultas explícitas (`Role::forTenant()`), `Role::create` sem contexto falha no banco e testes de "papéis diferentes por tenant" (cache incluído).

### D-25 — Último administrador, anti-escalação e concorrência *(implementada)*
- **Último administrador ativo** = vínculo ativo + usuário ativo + papel `admin` do tenant. Suspender, remover, retirar o papel e bloquear (CLI) são recusados se fosse o último.
- **Concorrência:** todas as operações que reduzem acesso rodam sob `TenantLock` (*lock* de linha em `tenants`), senão dois administradores removidos ao mesmo tempo passariam na checagem.
- **Anti-escalação:** ninguém concede permissões, atribui papel ou convida com papel que contenha permissões que o próprio autor não possui (`PrivilegeGuard`).
- **Remoção de vínculo** revoga papéis e tokens (um futuro convite não herda privilégios).

### D-26 — Ordem de gravação: banco + auditoria primeiro, sessão depois *(implementada)*
- A **sessão não é transacional**. Em `TenantSelector`, banco e auditoria ficam na transação e a sessão só muda **depois do commit**; assim a troca de tenant nunca vale sem registro. No login, a seleção automática é evento de autorização (falha fechada): se a auditoria falhar, o login continua **sem** tenant ativo.

### D-27 — Sessões antigas e "sair de outros dispositivos" *(implementada)*
- Usa o `AuthenticateSession` **do Sanctum** (já na pilha stateful) e `Auth::guard('web')->logoutOtherDevices()`. Um middleware próprio foi escrito, comprovado redundante e **removido**. O `AuthenticateSession` do Laravel não é usado (chama métodos ausentes no guard do Sanctum).

### D-28 — Convites *(implementada)*
- Aceite **público** (o token do e-mail é a credencial; busca deliberadamente sem escopo de tenant), com *throttle*; roda dentro do contexto do tenant do convite (o escopo global exige tenant até para o `UPDATE`). Respostas iguais para token desconhecido/usado/revogado. Conta nova exige nome e senha forte; conta existente não precisa de senha. Convite recusado para membro ativo/suspenso e quando já há convite pendente (coluna gerada + `UNIQUE`).

### D-29 — Escopo dos tokens de API *(APROVADA e IMPLEMENTADA)*
**Tokens de integração**
- Vinculados **obrigatoriamente** a um tenant (`tenant_id`, imutável; FK composta exige vínculo); **não operam em mais de um tenant** e não trocam de tenant (`tenant_fixed`).
- **Menor privilégio:** `abilities` **obrigatórias e explícitas**, escolhidas no catálogo de permissões. **Sem curinga**: `*` é recusado na emissão e **ignorado no uso** (token legado com `*` não recebe nada).
- **Autorização = abilities do token ∩ permissões vivas do usuário no tenant** (`User::hasPermissionTo`, por onde passam o Gate, `can:` e `$user->can()`). Se o emissor perde um papel, o token perde junto.
- **Ninguém delega o que não tem:** as abilities precisam caber nas permissões do emissor (`privilege_escalation`).
- **Expiração obrigatória**, máximo **90 dias** (+ teto `sanctum.expiration`); **revogação individual** (`DELETE /tokens/{id}`).
- **Nenhum token contorna o `SetTenantContext`:** um teste **estrutural** percorre todas as rotas e falha se uma rota de negócio não passar por ele.

**Tokens sem tenant:** só fluxos `auth/*` (`me`, `tenants`, `logout`); **proibidos** em todo endpoint de negócio; sem acesso global/administrativo.

**Sessões SPA:** continuam por cookie Sanctum; abilities não se aplicam; não são substituídas por tokens.

**Fora do escopo (não implementado):** superadmin, tokens globais de negócio, acesso simultâneo a vários tenants.

> **Acréscimo da implementação (sinalizar na homologação):** além do que foi definido, as quatro permissões de **gestão de acesso** (`tokens.manage`, `roles.manage`, `members.manage`, `members.invite`) **nunca** podem ser abilities de token (recusadas na emissão e ignoradas no uso). Evita que um token vazado emita outros tokens, altere papéis, suspenda usuários ou convide pessoas. É uma restrição **extra** e pode ser revertida por decisão sua (`PermissionCatalog::RESTRICTED_FOR_TOKENS`).

## Decisões pendentes (precisam de resposta)

| ID | Pergunta | Alternativas | Recomendação |
|---|---|---|---|
| **D-P01** | Como o tenant ativo é escolhido? | — | ✅ **APROVADA:** híbrido (sessão no servidor + token por tenant); subdomínio só pré-seleção futura; `X-Tenant` nunca é autoridade; vínculo revalidado a cada requisição; troca auditada. Ver D-05 |
| **D-P02** | Metodologia de **BDI** e **custo indireto** | percentual único; componentes; por grupo/item | ⏳ **ADIADA para a Fase 4.** Mantida a recomendação (componentes + percentual informado; escopo global/grupo/item; indiretos como linhas separadas). **Fórmula e incidência do desconto NÃO aprovadas.** Ajuste proposto a `budget_bdi`: `group_id`/`item_id` com FK composta |
| **D-P03** | Regimes de **encargos sociais** | com/sem desoneração/não se aplica | ⏳ **ADIADA para a Fase 4.** Mantidos `with_relief`, `without_relief`, `not_applicable`, persistidos em `budgets` **e** `budget_versions`; **sem cálculo próprio** de encargos no MVP. *Indício:* as bases SINAPI recebidas (MT, 2024-12) vêm em variantes **Desonerado** e **Não Desonerado**, coerente com os dois primeiros valores |
| **D-P04** | E-mail de login único global vs. e-mail por tenant | global (atual) | Manter global; rever se houver SSO corporativo |
| **D-P05** | Multi-moeda | não / futura | Não no MVP |
| **D-P06** | Retenção e particionamento de `audit_logs`, `inventory_movements` | partição mensal; arquivamento frio | Decidir com volume estimado antes de produção |
| **D-P07** | Anonimização LGPD de `users`/`employees` | rotina de anonimização preservando auditoria | Definir política jurídica de retenção |
| **D-P08** | MFA | TOTP/WebAuthn | Tabela `user_mfa_methods` futura; não bloqueia o modelo |
| **D-P09** | MySQL alvo: 8.4 LTS (Docker) vs 8.0.30 (Laragon local) | alinhar versões | Padronizar dev em 8.4 |
| **D-P10** | Busca textual | `FULLTEXT` MySQL × motor externo | Medir antes; sem motor externo no MVP |

## Revisão crítica (checklist do PROMPT 004 §11)

| Pergunta | Resultado |
|---|---|
| Todas as operacionais têm `tenant_id`? | Sim. Exceções intencionais: globais (`users`, `plans`, `permissions`, `reference_sources`, catálogo de unidades) e `audit_logs` de plataforma (`NULL`). |
| Relações permitem cruzar tenants? | Não nas FKs compostas. **Riscos residuais** (sem FK): polimórficas, snapshots, `*_by → users`, `party_id` de `contract_parties` — mitigados por aplicação + testes (ver [DB_CONSTRAINTS.md](DB_CONSTRAINTS.md) §2). |
| FKs sem índice? | Toda FK composta tem índice `(tenant_id, col)`. |
| Campos duplicados? | Duplicação **intencional** apenas nos snapshots de orçamento (D-08) e `totals_snapshot`. |
| N:N sem pivô? | Coberto: `user_companies`, `user_project_access`, `project_documents`, `contract_documents`, `document_links`, `budget_resource_items`, `schedule_dependencies`, Spatie. |
| Valores financeiros em DECIMAL? | Sim (D-07). |
| Orçamentos preservam versões? | Sim (`budget_versions`, D-08). |
| Composições preservam preços de referência? | Sim: cópia + `source_snapshot`. |
| Movimentos de estoque com histórico? | Sim, append-only (D-10). |
| Movimentações financeiras auditáveis? | Sim, append-only + estorno (D-09) + `audit_logs`. |
| Aprovações com histórico? | `budget_approvals`, `measurement_approvals`, `purchase_approvals` append-only. |
| Usuário global em vários tenants? | Sim (D-02). |
| Permissões respeitam o tenant ativo? | Sim, Spatie Teams (D-04); risco em jobs mitigado. |
| Documentos com controle de acesso? | `documents.visibility` + `document_permissions` + URL assinada (D-16). |
| Exclusões comprometem histórico? | Não: RESTRICT por padrão, soft delete só em mestres (D-12). |

## Riscos identificados

1. **Isolamento depende de disciplina** nas exceções sem FK (polimórficas/snapshots) → testes de acesso cruzado obrigatórios por módulo.
2. **Contexto Spatie em jobs/queues** pode ser esquecido → middleware de job + teste.
3. **Volume** de `audit_logs`, `inventory_movements`, `financial_transactions` → particionamento/arquivamento (D-P06).
4. **Complexidade de migrations** com FKs compostas e circularidades → plano em [DB_MIGRATION_PLAN.md](DB_MIGRATION_PLAN.md).
5. **Divergência 8.0.30 × 8.4** entre dev e containers (D-P09).
6. **Regras de negócio ainda indefinidas** (BDI/indiretos/encargos) podem exigir ajuste de `budget_*` (D-P02/P03) — por isso essas migrations vêm só depois da definição.
