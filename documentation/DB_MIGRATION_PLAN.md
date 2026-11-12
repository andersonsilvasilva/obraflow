# DB_MIGRATION_PLAN.md — ORDEM PROPOSTA DAS MIGRATIONS

> **Estado: a FASE 1 FOI EXECUTADA** (aprovação formal; 15 migrations em `obraflow-api/database/migrations/2026_11_12_2000NN_*`, aplicadas no banco `obraflow` e exercitadas em `obraflow_testing` no **MySQL 8.0.30**). As demais fases abaixo continuam **apenas planejadas**.
> **Pendente:** comprovar as mesmas migrations no **MySQL 8.4** (matriz do CI, [TESTES.md](TESTES.md) §4) antes da homologação.
> As migrations padrão do Laravel (`users`, `cache`, `jobs`) foram removidas; cache/sessão/fila usam drivers locais ([AMBIENTE_LOCAL.md](AMBIENTE_LOCAL.md)).

## Regras

1. Uma migration por tabela (ou grupo coeso), nome `YYYY_MM_DD_NNNNNN_create_<tabela>_table`.
2. Cada pai declara `UNIQUE (tenant_id, id)` na própria migration, **antes** de qualquer filha referenciá-lo.
3. FKs compostas só após as tabelas-pai existirem.
4. **`CHECK` e colunas geradas** via `DB::statement`/`virtualAs` dentro da migration; **sem triggers** (ver FASE1_MODELO §7).
5. Toda migration tem `down()` e teste de integridade (rejeição de referência cruzada entre tenants).
6. **Testar nas duas versões:** MySQL 8.0.30 (Laragon) e 8.4 (Docker).
7. Ambiente de dev: `migrate:fresh --seed` apenas com seeders de **desenvolvimento**; o catálogo de permissões é instalado por **comando idempotente**, não por seeder de demonstração.
8. **Pré-requisitos** antes da primeira migration: `timezone '+00:00'` na conexão MySQL; banco `obraflow` e usuário dedicado (exigem autorização — FASE1_MODELO §9).

## Fase 1 — Identidade, tenancy e acesso (**15 tabelas**)

| Ordem | Migration | Depende de | Observação |
|---|---|---|---|
| 1 | `failed_jobs` | — | Padrão Laravel |
| 2 | `users` | — | Global; `email` com `utf8mb4_0900_as_ci` |
| 3 | `password_reset_tokens` | — | PK `email` |
| 4 | `tenants` | — | Soft delete |
| 5 | `tenant_users` | 2, 4 | `UNIQUE (tenant_id, user_id)` — alvo de várias FKs compostas |
| 6 | `tenant_domains` | 4 | Coluna gerada `primary_marker` + `UNIQUE (tenant_id, primary_marker)` |
| 7 | `tenant_settings` | 4 | `setting_key`/`setting_value` |
| 8 | `permissions` | — | Catálogo global; `module` |
| 9 | `roles` | 4 | **`tenant_id NOT NULL`**, `UNIQUE (tenant_id, name, guard_name)`, `UNIQUE (tenant_id, id)`, `is_system` — **baseada no stub do Spatie, editada** |
| 10 | `model_has_permissions` | 5, 8 | FK composta → `tenant_users`; `CHECK model_type='user'` |
| 11 | `model_has_roles` | 5, 9 | **FK composta → `roles(tenant_id, id)`** e → `tenant_users`; `CHECK model_type='user'`; RESTRICT |
| 12 | `role_has_permissions` | 8, 9 | RESTRICT (o stub usa CASCADE) |
| 13 | `personal_access_tokens` | 4, 5 | Sanctum + `tenant_id NULL`; FK composta → `tenant_users`; `CHECK tokenable_type='user'` |
| 14 | `user_invitations` | 4, 5, 9 | FKs compostas → `tenant_users` (2×) e `roles`; coluna gerada `pending_email` |
| 15 | `audit_logs` | 2, 4 | Sem `updated_at`; `TIMESTAMP(6)`; índices de consulta |

**Não entram:** `plans`, `plan_features`, `subscriptions`, `subscription_items`, `subscription_usage`, `invoices`, `payments` (→ Fase 10), `units_of_measure` (→ Fase 2), empresas, obras e demais módulos.

**Entregáveis de teste da Fase 1:** cada FK composta e `CHECK` com teste de violação; `UNIQUE` e colunas geradas; round-trip de data em UTC; suíte rodando em 8.0.30 e 8.4.

## Fases seguintes (ordem proposta; nenhuma autorizada)

| Fase | Migrations | Depende de |
|---|---|---|
| **2 Cadastros** | `units_of_measure`, `companies`, `company_branches`, `customers`, `suppliers`, `contacts`, `addresses`, `user_companies`, `document_categories`, `documents`, `document_versions`, `document_links`, `document_permissions` | 1 |
| **3 Equipes e Obras** | `employees`, `employee_documents`, `employee_certifications`, `work_shifts`, `equipment`, `projects`, `project_members`, `project_settings`, `project_phases`, `project_status_history`, `project_documents`, `user_project_access` | 2 |
| **4 Referências e Orçamentos** | `reference_*` (8 tabelas), `budgets`, `budget_versions`, `budget_groups`, `budget_items`, `budget_compositions`, `budget_composition_items`, `budget_resources`, `budget_resource_items`, `budget_costs`, `budget_indirect_costs`, `budget_bdi`, `budget_approvals`, `budget_status_history`, `budget_imports` | 2, 3 — **só após D-P02 e D-P03** |
| **5 Contratos e Medições** | `contracts*`, `measurements*` | 3, 4 |
| **6 Planejamento e Diário** | `schedules*`, `construction_diaries*`, `equipment_allocations`, `equipment_maintenance`, `labor_allocations`, `attendance_records` | 3, 4 |
| **7 Estoque e Compras** | `warehouses*`, `inventory_*`, `stock_*`, `purchase_*`, `supplier_quote*`, `goods_receipt*` | 3 |
| **8 Financeiro** | `financial_*`, `accounts_*`, `cost_centers`, `payment_methods`, `cash_flow_entries` | 2, 3, 6 |
| **9 IA** | `ai_*` | 1 |
| **10 Plataforma** | `plans`, `plan_features`, `subscriptions`, `subscription_items`, `subscription_usage`, `invoices`, `payments`, `user_mfa_methods` (futura), `notifications`, `notification_preferences`, `user_activity_logs`, `integration_logs` | 1 |

(A numeração segue o [ROADMAP.md](ROADMAP.md).)

## Pontos de atenção

1. **Spatie:** não publicar/rodar a migration do pacote como está; criar a própria a partir do stub com as alterações da tabela da Fase 1 (`roles.tenant_id NOT NULL` + FKs compostas + RESTRICT). A config (`teams`, `team_foreign_key=tenant_id`) já está aplicada.
2. **Sanctum:** a migration do pacote é a base; acrescentar `tenant_id`, o índice `(tenant_id, tokenable_id)`, a FK composta e o `CHECK`.
3. **Referências circulares** (`budgets.current_version_id`, `documents.current_version_id`): coluna nula primeiro, FK em migration posterior.
4. **`projects.technical_manager_id → employees`** exige `employees` antes de `projects`.
5. **MySQL 8.0.30 × 8.4:** sem triggers; usuário da aplicação criado com `caching_sha2_password`; `lower_case_table_names` difere (Windows 1 × Linux 0) — nomes sempre minúsculos.
6. **Tabelas grandes** (`audit_logs`, `inventory_movements`): estratégia online (pt-osc/gh-ost) e particionamento a definir antes de produção (D-P06).
7. **`sql_require_primary_key`** pode estar ativo em serviços gerenciados: todas as tabelas têm PK.

## Testes por migration

- Cria/derruba sem erro em 8.0.30 e 8.4.
- Inserção cruzada entre tenants **falha** (`SQLSTATE 23000`).
- `UNIQUE`, `CHECK` e colunas geradas rejeitam violações.
- Exclusão de pai com filhos é bloqueada (`RESTRICT`).
