# DB_CONSTRAINTS.md — INTEGRIDADE, UNICIDADE E EXCLUSÃO

> As constraints da **Fase 1** (§7) estão **aplicadas** e cobertas por testes ([TESTES.md](TESTES.md)). As demais são proposta.

## 1. Chaves estrangeiras compostas (isolamento por tenant)

**Padrão:** toda tabela-pai referenciável por entidades operacionais declara

```sql
UNIQUE KEY uq_<tabela>_tenant_id (tenant_id, id)
```

e toda tabela-filha referencia o pai assim:

```sql
FOREIGN KEY (tenant_id, project_id) REFERENCES projects (tenant_id, id)
```

Efeito: o **banco** recusa um `budgets` do tenant A apontando para um `projects` do tenant B — o par `(tenant_id, project_id)` simplesmente não existe.

Exemplo (esboço de migration, *não aplicado*):

```php
$table->unsignedBigInteger('tenant_id');
$table->unsignedBigInteger('project_id');
$table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
$table->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('projects')->restrictOnDelete();
```

### Compatibilidade com Laravel/Eloquent

- A PK continua sendo `id`; Eloquent, `find()`, rotas e relações `belongsTo(Project::class)` funcionam sem alteração.
- A FK composta é apenas uma constraint de banco. O Eloquent usa a coluna `project_id` normalmente.
- O Schema Builder aceita `foreign([...])->references([...])`.
- Custo: um índice `UNIQUE (tenant_id, id)` extra por tabela-pai e um índice por FK composta. Aceito (D-03).
- Em `NULL`: FK composta com `project_id NULL` não é verificada (comportamento MySQL `MATCH SIMPLE`), correto para vínculos opcionais.

## 2. Onde a FK composta NÃO se aplica (e a mitigação)

| Caso | Motivo | Mitigação obrigatória |
|---|---|---|
| Tabelas **polimórficas** (`contacts`, `addresses`, `document_links`, `audit_logs.entity_*`) | `*_type/*_id` não permite FK | Trait `BelongsToTenant`; *morph map* com allowlist; validação de que o alvo pertence ao mesmo tenant; teste de acesso cruzado |
| Referências a **catálogos globais** (`plans`, `permissions`, `units_of_measure` global, `reference_*` oficiais) | Não têm `tenant_id` | Somente leitura para tenants; FK simples |
| **Snapshots** de orçamento → referência (`source_composition_id`, `source_resource_id`) | Cópia imutável deve sobreviver à mudança/remoção da referência | Sem FK; guarda `source_snapshot` (fonte, competência, localidade, versão) |
| `tenant_users`, `users` | São identidade global | `UNIQUE (tenant_id, user_id)`; verificação `active` na camada de acesso |
| Colunas `*_by` → `users` | `users` é global | FK simples para `users.id`; usuário deve ter `tenant_users` ativo (validação) |

## 3. Unicidade

| Tabela | Restrição |
|---|---|
| `users` | `UNIQUE (email)` |
| `tenants` | `UNIQUE (slug)` |
| `tenant_users` | `UNIQUE (tenant_id, user_id)` |
| `user_companies` | `UNIQUE (tenant_id, user_id, company_id)` |
| `user_project_access` | `UNIQUE (tenant_id, user_id, project_id)` |
| `companies` | `UNIQUE (tenant_id, document_number)` |
| `customers` / `suppliers` | `UNIQUE (tenant_id, company_id, document_number)` |
| `projects` | `UNIQUE (tenant_id, code)` |
| `budgets` | `UNIQUE (tenant_id, code)` |
| `budget_versions` | `UNIQUE (tenant_id, budget_id, version_number)` |
| `contracts`, `purchase_requests`, `purchase_orders`, `invoices` | `UNIQUE (tenant_id, number)` |
| `schedule_tasks` | `UNIQUE (tenant_id, schedule_id, wbs_code)` |
| `schedule_dependencies` | `UNIQUE (tenant_id, predecessor_id, successor_id)` + `CHECK (predecessor_id <> successor_id)` |
| `construction_diaries` | `UNIQUE (tenant_id, project_id, diary_date)` |
| `attendance_records` | `UNIQUE (tenant_id, employee_id, work_date)` |
| `inventory_balances` | `UNIQUE (tenant_id, warehouse_id, item_id, location_id)` |
| `document_versions` | `UNIQUE (tenant_id, document_id, version_number)` |
| `reference_*` | ver [DATABASE.md](DATABASE.md) §2.7 |
| Todas as tabelas-pai | `UNIQUE (tenant_id, id)` |

`uuid` públicos: `UNIQUE (uuid)` global.

## 4. CHECK constraints (MySQL ≥ 8.0.16)

- `project_members`: ao menos `user_id` ou `employee_id`.
- `budget_composition_items`, `reference_composition_items`: ao menos `resource_id` ou `child_composition_id`.
- `financial_transactions`: não ligar a título a pagar **e** a receber ao mesmo tempo.
- `purchase_approvals`: exatamente um alvo (`request_id` xor `order_id`).
- Valores: `quantity >= 0`, `unit_price >= 0`, `percent BETWEEN 0 AND 100` onde aplicável (exceto movimentos de estoque assinados).

## 5. Regras de exclusão

Padrão: **`ON DELETE RESTRICT`**. A exclusão física de dados de negócio é exceção.

| Categoria | Política |
|---|---|
| Cadastros mestres (`tenants`, `companies`, `customers`, `suppliers`, `projects`, `employees`, `equipment`) | **Soft delete**; `RESTRICT` nas FKs |
| Financeiro (`accounts_*`, `financial_transactions*`, `invoices`, `payments`) | **Nunca** excluir; cancelar por `status`, corrigir por estorno (`reversal`) |
| Contratos, medições, orçamentos aprovados | **Nunca** excluir; `archived`/`canceled`; versões imutáveis |
| Históricos e auditoria (`*_history`, `*_approvals`, `audit_logs`, `schedule_progress_history`) | **Append-only**: sem UPDATE/DELETE |
| Estoque (`inventory_movements*`) | Append-only; correção por movimento de estorno |
| Documentos | `documents` arquivado; `document_versions` append-only; remoção de objeto no S3 só por rotina de retenção aprovada |
| **Linhas-filhas de rascunho** (ex.: `budget_composition_items` de versão `draft`, `purchase_request_items` de solicitação `draft`) | `ON DELETE CASCADE` **permitido** — só quando o pai é estritamente rascunho e a regra é validada na aplicação |
| `users` | Sem exclusão física; `status=blocked`; anonimização LGPD por rotina específica preservando `audit_logs` |

### Proteção do histórico no banco

- Usuário de aplicação **sem** `UPDATE`/`DELETE` em `audit_logs` e tabelas `append-only`.
- Triggers de defesa (opcional, a aprovar): bloquear UPDATE/DELETE em itens de `budget_versions` com `locked_at` preenchido.

## 6. Índices

- Toda FK composta gera índice `(tenant_id, fk_col)` — evita *table scan* no `RESTRICT`.
- Consultas frequentes: `(tenant_id, status)`, `(tenant_id, due_date)` em títulos, `(tenant_id, project_id, <data>)` em diário/medições/movimentos, `(tenant_id, created_at)` em auditoria.
- `tenant_id` é **sempre a primeira coluna** de índices operacionais.
- Textos para busca (`name`, `description`): `FULLTEXT` a avaliar após medição (pendência).

## 7. Fase 1 — constraints específicas (validadas em MySQL 8.0.30)

Especificação completa em [FASE1_MODELO.md](FASE1_MODELO.md); evidência em [validacao-fase1/](validacao-fase1/README.md).

| Tabela | Constraint | Garante |
|---|---|---|
| `roles` | `tenant_id NOT NULL`; `UNIQUE (tenant_id, name, guard_name)`; `UNIQUE (tenant_id, id)` | Sem papel global; unicidade exata; alvo de FK composta |
| `model_has_roles` | FK `(tenant_id, role_id) → roles(tenant_id, id)` | Papel do mesmo tenant do vínculo |
| `model_has_roles` / `model_has_permissions` | FK `(tenant_id, model_id) → tenant_users(tenant_id, user_id)`; `CHECK (model_type = 'user')` | Usuário com vínculo naquele tenant |
| `personal_access_tokens` | FK `(tenant_id, tokenable_id) → tenant_users(...)`; `CHECK (tokenable_type = 'user')` | Token com tenant só para usuário vinculado (`NULL` não é avaliado) |
| `user_invitations` | FKs compostas para `tenant_users` (convidante, aceitante) e `roles`; `UNIQUE (tenant_id, pending_email)` (coluna gerada); `CHECK` de convite aceito | Convite e papel do mesmo tenant; um convite pendente por e-mail |
| `tenant_domains` | `UNIQUE (tenant_id, primary_marker)` (coluna gerada) | Um domínio primário por tenant |
| `tenant_users` | `UNIQUE (tenant_id, user_id)`; `CHECK` de status | Um vínculo por par; alvo das FKs acima |
| `users` | `UNIQUE (email)` com `utf8mb4_0900_as_ci` | E-mail único sem caixa; acento diferencia |
| `audit_logs` | sem `updated_at`; privilégios de produção INSERT/SELECT | Append-only (sem triggers) |

**Regras gerais da fase:** todas as FKs `RESTRICT`; sem triggers (D-22); `CHECK` não usa colunas de FK com `CASCADE`/`SET NULL` (não há nenhuma).
