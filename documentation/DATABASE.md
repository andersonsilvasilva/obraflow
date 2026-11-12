# DATABASE.md — MODELO DE DADOS DO OBRAFLOW

> **Status:** a **Fase 1 (15 tabelas) está IMPLEMENTADA** (migrations aplicadas no MySQL 8.0.30; 8.4 pendente no CI). O restante deste documento é a **proposta** das fases seguintes: nenhuma outra migration foi criada.
> Este documento **substitui** o rascunho anterior, que colocava `tenant_id` em `users` — decisão revogada (ver [DB_DECISIONS.md](DB_DECISIONS.md) D-02).
> **Fase 1 (15 tabelas): a especificação autoritativa é [FASE1_MODELO.md](FASE1_MODELO.md)** — validada em MySQL 8.0.30 (87/87). Onde este documento divergir dela para essas tabelas, vale a FASE1_MODELO. Billing/assinaturas ficam para a Fase 10.

Documentos relacionados: [ERD.md](ERD.md) · [DATA_DICTIONARY.md](DATA_DICTIONARY.md) · [DB_CONSTRAINTS.md](DB_CONSTRAINTS.md) · [DB_MIGRATION_PLAN.md](DB_MIGRATION_PLAN.md) · [DB_DECISIONS.md](DB_DECISIONS.md) · [MULTITENANCY.md](MULTITENANCY.md)

## 1. Tecnologia e convenções

- MySQL **8.4 LTS** (ou 8.0.x compatível), InnoDB, `utf8mb4`, collation `utf8mb4_0900_ai_ci`.
- Tabelas no plural, `snake_case`, nomes em inglês.
- PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`. Identificador público/sincronização: `uuid CHAR(36)` com `UNIQUE`, apenas onde há exposição externa (marcado `uuid`).
- Datas em **UTC**; conversão para `tenants.timezone` somente na apresentação.
- Precisões (justificativa em D-07):

| Tipo | Precisão | Uso |
|---|---|---|
| `money` | `DECIMAL(15,2)` | valores monetários persistidos (totais, títulos, pagamentos) |
| `price` | `DECIMAL(15,4)` | preço unitário de insumo/serviço (referências trazem mais de 2 casas) |
| `qty` | `DECIMAL(18,4)` | quantidades |
| `coef` | `DECIMAL(18,6)` | coeficientes de composição |
| `pct` | `DECIMAL(9,4)` | percentuais (25,0000 = 25%) |

- **Nunca** `FLOAT`/`DOUBLE` para valores financeiros, quantidades ou coeficientes.
- Datas de auditoria: `created_at`, `updated_at` (`TIMESTAMP` UTC). `deleted_at` só em cadastros mestres (D-12).

### Colunas-padrão

Salvo indicação contrária, toda tabela **operacional** possui:

`id`, `tenant_id` (FK → `tenants.id`), `created_at`, `updated_at`

e `UNIQUE (tenant_id, id)` — pré-requisito para chaves estrangeiras compostas (ver [DB_CONSTRAINTS.md](DB_CONSTRAINTS.md)). Abaixo só são listadas as colunas **adicionais**.

Notação: `FK(tenant_id, x_id) → tabela(tenant_id, id)` = chave estrangeira composta (isolamento garantido pelo banco). `SD` = soft delete (`deleted_at`). `G` = tabela **global** (sem `tenant_id`).

## 2. Domínios

### 2.1 Administração SaaS

#### tenants  (G — é a própria raiz)
`id`, `uuid`, `name str(150)`, `slug str(80) UNIQUE`, `legal_name str(200) NULL`, `document_number str(20) NULL`, `email str(190) NULL`, `phone str(30) NULL`, `status enum(trial,active,suspended,canceled)`, `timezone str(64) DEFAULT 'America/Sao_Paulo'`, `locale str(10) DEFAULT 'pt_BR'`, `created_at`, `updated_at`, `deleted_at` (SD).
Obrigatórios: `name`, `slug`, `status`, `timezone`, `locale`. Opcionais: demais (a conta SaaS não é necessariamente uma pessoa jurídica).

#### tenant_users  (G — associação)
`id`, `tenant_id FK`, `user_id FK→users`, `membership_status enum(active,suspended,removed)`, `joined_at ts NULL`, `last_used_at ts NULL`, `created_at`, `updated_at`. `UNIQUE (tenant_id, user_id)` (alvo de FKs compostas); índice `(user_id, membership_status)`.
Fonte de verdade para "este usuário pertence a este tenant". **`invited` foi removido:** o estado "convidado" vive em `user_invitations`; o vínculo nasce na aceitação. `removed` preserva histórico.

#### tenant_domains
`domain str(190) UNIQUE`, `is_primary bool`, `verified_at ts NULL`.

#### tenant_settings
`setting_key str(100)`, `setting_value json`. `UNIQUE (tenant_id, setting_key)`. (`key` é palavra reservada do MySQL.)

#### plans  (G)
`code str(50) UNIQUE`, `name`, `description`, `status enum(draft,active,retired)`, `billing_cycle enum(monthly,yearly)`, `price money NULL`, `currency char(3) DEFAULT 'BRL'`. *Sem valores comerciais fixados neste modelo.*

#### plan_features  (G)
`plan_id FK`, `feature_key str(80)`, `feature_value str(190) NULL`, `limit_value bigint NULL`. `UNIQUE (plan_id, feature_key)`.

#### subscriptions
`plan_id FK`, `status enum(trialing,active,past_due,canceled,expired)`, `starts_at`, `trial_ends_at NULL`, `current_period_start`, `current_period_end`, `canceled_at NULL`, `cancel_reason NULL`.

#### subscription_items
`subscription_id`, `feature_key`, `quantity int`, `unit_price price NULL`. FK composta → `subscriptions`.

#### subscription_usage
`subscription_id`, `feature_key`, `period_start date`, `period_end date`, `used bigint`. `UNIQUE (subscription_id, feature_key, period_start)`.

#### invoices
`uuid`, `subscription_id`, `number str(40)`, `status enum(draft,open,paid,void,uncollectible)`, `issued_at`, `due_at`, `amount money`, `currency`. `UNIQUE (tenant_id, number)`.

#### payments
`invoice_id`, `provider str(40)`, `provider_reference str(120) NULL`, `status enum(pending,succeeded,failed,refunded)`, `amount money`, `paid_at NULL`. *Sem dados de cartão — apenas referência do provedor.*

### 2.2 Usuários, segurança e acesso

#### users  (G)
`id`, `uuid`, `name`, `email str(190) UNIQUE` (collation `utf8mb4_0900_as_ci`: ignora caixa, diferencia acento), `email_verified_at NULL`, `password`, `status enum(active,blocked)`, `last_login_at NULL`, `remember_token`, `created_at`, `updated_at`. **Sem `tenant_id`** (D-02). MFA futuro: tabela `user_mfa_methods` (não incluída na contagem do MVP).

#### user_companies
`user_id FK`, `company_id`, `status enum(active,inactive)`. `UNIQUE (tenant_id, user_id, company_id)`. FK composta → `companies`. Garantia adicional por aplicação: `user_id` deve ter `tenant_users` ativo no `tenant_id`.

#### user_project_access
`user_id`, `project_id`, `access_level enum(view,edit,manage)`, `granted_by NULL`. `UNIQUE (tenant_id, user_id, project_id)`. FK composta → `projects`.

#### roles / permissions / model_has_roles / model_has_permissions / role_has_permissions  (Spatie, `teams=true`)
Tabelas do pacote, **não duplicadas**, com migration própria baseada no stub. Chave de equipe = `tenant_id` (`team_foreign_key`, já em `config/permission.php`). **Sem papéis globais:** `roles.tenant_id NOT NULL` (+ `is_system`). `permissions` é catálogo global (+ `module`). `model_has_*` têm `tenant_id` na PK e **FKs compostas** `(tenant_id, role_id) → roles(tenant_id, id)` e `(tenant_id, model_id) → tenant_users(tenant_id, user_id)`, com `CHECK (model_type = 'user')`; todas as FKs são `RESTRICT`. Detalhes em [FASE1_MODELO.md](FASE1_MODELO.md) §3.7–3.11 e [MULTITENANCY.md](MULTITENANCY.md) §6.

#### personal_access_tokens  (Sanctum)
Tabela do pacote **+** `tenant_id BIGINT UNSIGNED NULL` com FK composta `(tenant_id, tokenable_id) → tenant_users(tenant_id, user_id)` e `CHECK (tokenable_type = 'user')` — D-05. **Token sem tenant não acessa dados de negócio** (regra de borda, D-20).

#### user_invitations
`email`, `invited_by`, `role_id`, `token_hash char(64) ascii_bin UNIQUE`, `status enum(pending,accepted,expired,revoked)`, `expires_at`, `accepted_at NULL`, `accepted_user_id NULL`, `revoked_at NULL`, `pending_email` (coluna gerada) com `UNIQUE (tenant_id, pending_email)`. FKs compostas: convidante e aceitante → `tenant_users`; `role_id` → `roles(tenant_id, id)`. Guarda **hash** do token, nunca o token.

#### password_reset_tokens  (G)
`email PK`, `token` (hash), `created_at`.

### 2.3 Cadastro empresarial

#### companies  (SD)
`uuid`, `legal_name`, `trade_name NULL`, `document_number str(20)` (CNPJ), `state_registration NULL`, `municipal_registration NULL`, `email`, `phone`, `status enum(active,inactive)`. `UNIQUE (tenant_id, document_number)`.

#### company_branches  (SD)
`company_id`, `name`, `document_number NULL`, `status`. FK composta → `companies`. Endereço via `addresses`.

#### customers  (SD)
`uuid`, `company_id`, `type enum(person,company)`, `document_number NULL`, `name`, `email NULL`, `phone NULL`, `status`. `UNIQUE (tenant_id, company_id, document_number)` quando não nulo.

#### suppliers  (SD)
`uuid`, `company_id`, `document_number`, `legal_name`, `trade_name NULL`, `email`, `phone`, `status`. `UNIQUE (tenant_id, company_id, document_number)`.

#### contacts
Contatos de qualquer entidade: `contactable_type`, `contactable_id`, `name`, `role NULL`, `email`, `phone`, `is_primary bool`. Índice `(tenant_id, contactable_type, contactable_id)`. Polimórfica (D-13).

#### addresses
`addressable_type`, `addressable_id`, `kind enum(main,billing,delivery,site)`, `street`, `number`, `complement`, `district`, `city`, `state char(2)`, `postal_code`, `latitude decimal(10,7) NULL`, `longitude decimal(10,7) NULL`. Polimórfica.

### 2.4 Obras

#### projects  (SD)
`uuid`, `company_id`, `customer_id NULL`, `code str(30)`, `name`, `description NULL`, `technical_manager_id NULL` (→ `employees`), `contract_value money NULL`, `status enum(planning,in_progress,paused,delayed,completed,canceled)`, `progress pct`, `start_date`, `expected_end_date`, `actual_end_date NULL`, `created_by FK→users`. `UNIQUE (tenant_id, code)`. FK compostas → `companies`, `customers`. Endereço via `addresses` (kind `site`).

#### project_members
`project_id`, `user_id NULL`, `employee_id NULL`, `role str(60)`, `started_at`, `ended_at NULL`. `CHECK (user_id IS NOT NULL OR employee_id IS NOT NULL)`.

#### project_settings
`project_id`, `setting_key`, `setting_value json`. `UNIQUE (tenant_id, project_id, setting_key)`.

#### project_phases
`project_id`, `parent_id NULL`, `name`, `sort_order`, `planned_start`, `planned_end`.

#### project_status_history  (append-only)
`project_id`, `from_status NULL`, `to_status`, `changed_by`, `reason NULL`, `changed_at`.

#### project_documents
`project_id`, `document_id`, `category NULL`. `UNIQUE (tenant_id, project_id, document_id)`.

### 2.5 Contratos

#### contracts
`uuid`, `company_id`, `project_id NULL`, `kind enum(customer,supplier,service)`, `number`, `object text`, `total_value money`, `status enum(draft,active,suspended,completed,canceled)`, `signed_at NULL`, `starts_at`, `ends_at NULL`, `readjustment_rule NULL`. `UNIQUE (tenant_id, number)`.

#### contract_parties
`contract_id`, `party_type enum(company,customer,supplier)`, `party_id`, `role enum(contractor,contracted,guarantor,witness)`. `party_id` polimórfico controlado por `party_type`.

#### contract_items
`contract_id`, `code`, `description`, `unit_id`, `quantity qty`, `unit_price price`, `total money`, `budget_item_id NULL` (vínculo ao item orçado).

#### contract_addenda
`contract_id`, `number`, `kind enum(value,term,scope,mixed)`, `value_delta money`, `new_end_date NULL`, `description`, `signed_at NULL`, `status`.

#### contract_documents
`contract_id`, `document_id`, `kind`.

#### contract_status_history  (append-only)
`contract_id`, `from_status`, `to_status`, `changed_by`, `reason`, `changed_at`.

### 2.6 Orçamentos e composições

Princípio: **orçamento aprovado é imutável e autossuficiente** — itens e insumos guardam *cópia* de código, descrição, unidade, coeficiente e preço, mais a referência (sem FK obrigatória) à origem. Mudanças futuras nas referências nunca alteram versões aprovadas (D-08).

#### budgets
`uuid`, `company_id`, `project_id NULL`, `customer_id NULL`, `code`, `title`, `mode enum(customer,manual,reference)`, `reference_dataset_id NULL` (→ `reference_datasets`, sem FK composta), `state char(2)`, `social_charges_regime enum(with_relief,without_relief,not_applicable)`, `reference_date date`, `status enum(draft,in_review,approved,rejected,archived)`, `current_version_id NULL`, `owner_id FK→users`, `notes NULL`. `UNIQUE (tenant_id, code)`.

#### budget_versions
`budget_id`, `version_number int`, `label NULL`, `status enum(draft,in_review,approved,rejected,superseded)`, `created_by`, `approved_at NULL`, `locked_at NULL`, `totals_snapshot json`. `UNIQUE (tenant_id, budget_id, version_number)`. Com `locked_at` preenchido, itens da versão são somente leitura (aplicação + trigger de defesa — D-08).

#### budget_groups
`budget_version_id`, `parent_id NULL`, `code`, `name`, `sort_order`. Hierarquia (EAP/etapas).

#### budget_items
`budget_version_id`, `group_id`, `code`, `description`, `unit_id`, `quantity qty`, `unit_cost price`, `bdi_percent pct`, `discount_percent pct`, `total_cost money`, `total_sale money`, `source enum(official,custom,negotiated,manual)`, `source_composition_id NULL` (→ `reference_compositions`, **sem FK**), `sort_order`.

#### budget_compositions
Composição **instanciada** no orçamento: `budget_version_id`, `budget_item_id NULL`, `code`, `description`, `unit_id`, `unit_cost price`, `source enum(official,custom,negotiated,manual)`, `source_composition_id NULL`, `source_snapshot json NULL` (fonte, competência, localidade, versão).

#### budget_composition_items
`budget_composition_id`, `resource_id NULL`, `child_composition_id NULL`, `kind enum(material,labor,equipment,service,composition)`, `code`, `description`, `unit_id`, `coefficient coef`, `unit_price price`, `price_origin enum(official,custom,negotiated)`, `cost money`. `CHECK (resource_id IS NOT NULL OR child_composition_id IS NOT NULL)`.

#### budget_resources
Insumos consolidados do orçamento: `budget_version_id`, `kind enum(material,labor,equipment,service)`, `code`, `description`, `unit_id`, `quantity qty`, `unit_price price`, `price_origin enum(official,custom,negotiated)`, `source_resource_id NULL`, `total money`. `UNIQUE (tenant_id, budget_version_id, kind, code, price_origin)`.

#### budget_resource_items
Rastreia de onde vem cada quantidade do insumo: `budget_resource_id`, `budget_item_id`, `quantity qty`.

#### budget_costs
Totais por versão e categoria (cache auditável, recalculável): `budget_version_id`, `category enum(direct,material,labor,equipment,service)`, `amount money`, `calculated_at`.

#### budget_indirect_costs
`budget_version_id`, `description`, `method enum(fixed,percent_of_direct)`, `percent pct NULL`, `amount money`. *Metodologia pendente de definição de negócio (D-P02).*

#### budget_bdi
`budget_version_id`, `scope enum(global,group,item)`, `scope_id NULL`, `components json` (administração, lucro, impostos…), `percent pct`.

#### budget_approvals
`budget_version_id`, `decision enum(approved,rejected)`, `decided_by FK→users`, `decided_at`, `comment NULL`, `totals_hash char(64)`. Append-only.

#### budget_status_history  (append-only)
`budget_id`, `budget_version_id NULL`, `from_status`, `to_status`, `changed_by`, `changed_at`, `reason NULL`.

#### budget_imports
`budget_id NULL`, `source enum(spreadsheet,reference_dataset,other)`, `file_document_id NULL`, `status enum(pending,processing,done,failed)`, `rows_total`, `rows_imported`, `errors json NULL`, `created_by`.

#### units_of_measure  (G ou por tenant)
`code str(10)`, `name`, `tenant_id NULL` (`NULL` = catálogo global). `UNIQUE (tenant_id, code)`.

### 2.7 Referências técnicas (SINAPI, SUDECAP…)

Tabelas **globais e somente-leitura para tenants** quando oficiais (`tenant_id NULL`); um tenant pode ter bases próprias (`tenant_id` preenchido). **Nenhum valor é inventado**: dados entram exclusivamente por importação rastreável.

#### reference_sources  (G)
`code str(30) UNIQUE` (ex.: `SINAPI`), `name`, `publisher`, `country char(2)`, `website NULL`.

#### reference_datasets
`tenant_id NULL`, `source_id`, `name`, `version str(40)`, `kind enum(official_import,tenant_custom)`, `charges_regime enum(with_relief,without_relief,not_applicable,mixed)`, `imported_at`, `imported_by NULL`, `file_sha256 char(64)`, `status enum(importing,active,superseded,failed)`. `UNIQUE (source_id, version, tenant_id, charges_regime)`.

#### reference_competencies
`dataset_id`, `year smallint`, `month tinyint`. `UNIQUE (dataset_id, year, month)`.

#### reference_locations
`dataset_id`, `state char(2)`, `city NULL`, `ibge_code NULL`. `UNIQUE (dataset_id, state, city)`.

#### reference_resources
`dataset_id`, `code`, `description`, `unit_id`, `kind enum(material,labor,equipment,service)`. `UNIQUE (dataset_id, code)`.

#### reference_resource_prices
`resource_id`, `competency_id`, `location_id`, `price price`, `price_origin enum(official,custom,negotiated)`, `is_charges_included bool`. `UNIQUE (resource_id, competency_id, location_id, price_origin)`.

#### reference_compositions
`dataset_id`, `code`, `description`, `unit_id`, `group_label NULL`. `UNIQUE (dataset_id, code)`.

#### reference_composition_items
`composition_id`, `resource_id NULL`, `child_composition_id NULL`, `coefficient coef`.

### 2.8 Planejamento

#### schedules
`project_id`, `name`, `status enum(draft,active,archived)`, `baseline_id NULL`, `created_by`.

#### schedule_tasks
`schedule_id`, `parent_id NULL`, `wbs_code`, `name`, `kind enum(task,summary,milestone)`, `planned_start`, `planned_end`, `actual_start NULL`, `actual_end NULL`, `percent_complete pct`, `budget_item_id NULL`, `sort_order`. `UNIQUE (tenant_id, schedule_id, wbs_code)`.

#### schedule_dependencies
`predecessor_id`, `successor_id`, `type enum(FS,SS,FF,SF)`, `lag_days int`. `UNIQUE (tenant_id, predecessor_id, successor_id)`; `CHECK (predecessor_id <> successor_id)`.

#### schedule_milestones
`schedule_id`, `task_id NULL`, `name`, `due_date`, `achieved_at NULL`.

#### schedule_baselines
`schedule_id`, `name`, `created_by`, `snapshot json` (datas planejadas congeladas).

#### schedule_progress_history  (append-only)
`task_id`, `percent_complete pct`, `recorded_at`, `recorded_by`, `note NULL`.

### 2.9 Diário de obras

#### construction_diaries
`uuid`, `project_id`, `diary_date date`, `status enum(open,submitted,approved)`, `author_id FK→users`, `approved_by NULL`, `approved_at NULL`, `summary NULL`. `UNIQUE (tenant_id, project_id, diary_date)`.

#### construction_diary_activities
`diary_id`, `schedule_task_id NULL`, `description`, `quantity qty NULL`, `unit_id NULL`.

#### construction_diary_occurrences
`diary_id`, `kind enum(incident,interference,visit,delay,other)`, `description`, `impact NULL`.

#### construction_diary_teams
`diary_id`, `team_label`, `headcount int`, `hours qty`.

#### construction_diary_equipment
`diary_id`, `equipment_id`, `hours_used qty`.

#### construction_diary_weather
`diary_id`, `period enum(morning,afternoon,night)`, `condition enum(clear,cloudy,rain,storm)`, `workable bool`.

#### construction_diary_attachments
`diary_id`, `document_id`, `caption NULL`.

### 2.10 Medições

#### measurements
`uuid`, `project_id`, `contract_id NULL`, `number int`, `kind enum(physical,contractual)`, `period_start`, `period_end`, `status enum(draft,submitted,approved,rejected)`, `total_value money`. `UNIQUE (tenant_id, project_id, contract_id, number)`.

#### measurement_items
`measurement_id`, `contract_item_id NULL`, `budget_item_id NULL`, `quantity_period qty`, `quantity_accumulated qty`, `unit_price price`, `value money`.

#### measurement_attachments
`measurement_id`, `document_id`.

#### measurement_approvals  (append-only)
`measurement_id`, `decision`, `decided_by`, `decided_at`, `comment NULL`.

#### measurement_history  (append-only)
`measurement_id`, `event`, `old_values json NULL`, `new_values json NULL`, `changed_by`, `changed_at`.

### 2.11 Financeiro

Separação **título** (obrigação/direito) × **movimentação** (fato realizado) — D-09.

#### financial_accounts
`company_id`, `name`, `kind enum(bank,cash,card)`, `bank_code NULL`, `currency char(3)`, `opening_balance money`, `status`.

#### financial_categories
`parent_id NULL`, `code`, `name`, `kind enum(income,expense)`. `UNIQUE (tenant_id, code)`.

#### cost_centers
`company_id`, `parent_id NULL`, `code`, `name`, `status`. Hierárquica. `UNIQUE (tenant_id, company_id, code)`.

#### payment_methods
`name`, `kind enum(pix,boleto,transfer,card,cash,check)`, `status`.

#### accounts_payable
`uuid`, `company_id`, `project_id NULL`, `contract_id NULL`, `supplier_id`, `category_id`, `cost_center_id NULL`, `document_number NULL`, `installment_group uuid`, `installment_number smallint`, `installments_total smallint`, `issue_date`, `due_date`, `amount money`, `interest money`, `penalty money`, `discount money`, `amount_paid money`, `status enum(open,partial,paid,canceled)`. Índices `(tenant_id, status, due_date)`, `(tenant_id, supplier_id)`.

#### accounts_receivable
Mesma estrutura, com `customer_id` no lugar de `supplier_id`.

#### financial_transactions  (append-only; estorno por contrapartida)
`uuid`, `company_id`, `account_id`, `kind enum(payment,receipt,transfer_in,transfer_out,adjustment,reversal)`, `payable_id NULL`, `receivable_id NULL`, `payment_method_id NULL`, `amount money`, `occurred_on date`, `reverses_id NULL` (→ própria tabela), `reconciliation_id NULL`, `created_by`. `CHECK (NOT (payable_id IS NOT NULL AND receivable_id IS NOT NULL))`. **Sem soft delete e sem UPDATE de valor.**

#### financial_transaction_items
Rateio da movimentação: `transaction_id`, `category_id`, `cost_center_id NULL`, `project_id NULL`, `amount money`. Soma dos itens = `amount` (validado na aplicação).

#### cash_flow_entries
Projeção/realizado consolidado: `company_id`, `project_id NULL`, `entry_date`, `kind enum(forecast,actual)`, `direction enum(in,out)`, `amount money`, `source_type`, `source_id`.

#### financial_reconciliations
`account_id`, `period_start`, `period_end`, `statement_balance money`, `status enum(open,closed)`, `closed_by NULL`, `closed_at NULL`.

### 2.12 Compras e suprimentos

Fluxo: **Solicitação → Aprovação → Cotação → Pedido → Recebimento.**

#### purchase_requests
`uuid`, `project_id`, `number`, `requester_id FK→users`, `priority enum(low,medium,high,urgent)`, `status enum(draft,pending,approved,rejected,quoting,ordered,received,canceled)`, `needed_by NULL`, `notes NULL`. `UNIQUE (tenant_id, number)`.

#### purchase_request_items
`request_id`, `description`, `unit_id`, `quantity qty`, `inventory_item_id NULL`, `budget_item_id NULL`.

#### purchase_approvals  (append-only)
`request_id NULL`, `order_id NULL`, `decision`, `decided_by`, `decided_at`, `comment`. `CHECK` exatamente um alvo.

#### supplier_quotes
`request_id`, `supplier_id`, `status enum(requested,received,selected,rejected)`, `valid_until NULL`, `total money`.

#### supplier_quote_items
`quote_id`, `request_item_id`, `unit_price price`, `quantity qty`, `total money`, `lead_time_days NULL`.

#### purchase_orders
`uuid`, `company_id`, `project_id NULL`, `supplier_id`, `quote_id NULL`, `number`, `status enum(draft,approved,sent,partially_received,received,canceled)`, `total money`. `UNIQUE (tenant_id, number)`.

#### purchase_order_items
`order_id`, `request_item_id NULL`, `description`, `unit_id`, `quantity qty`, `unit_price price`, `total money`, `quantity_received qty`.

#### goods_receipts
`order_id`, `warehouse_id NULL`, `number`, `received_at`, `received_by`, `invoice_number NULL`, `status enum(draft,posted,canceled)`.

#### goods_receipt_items
`receipt_id`, `order_item_id`, `quantity qty`, `condition enum(ok,damaged,rejected)`.

### 2.13 Estoque

Saldo é **derivado de movimentações** (D-10).

#### warehouses
`company_id`, `project_id NULL`, `code`, `name`, `status`.

#### warehouse_locations
`warehouse_id`, `code`, `description NULL`.

#### inventory_items
`code`, `description`, `unit_id`, `kind enum(material,tool,consumable)`, `min_stock qty NULL`, `status`. `UNIQUE (tenant_id, code)`.

#### inventory_balances  (projeção materializada, reconstruível)
`warehouse_id`, `location_id NULL`, `item_id`, `quantity qty`, `reserved qty`, `average_cost price`, `last_movement_id`. `UNIQUE (tenant_id, warehouse_id, item_id, location_id)`.

#### inventory_movements  (append-only)
`uuid`, `warehouse_id`, `kind enum(receipt,issue,transfer,adjustment,return,reversal)`, `reference_type NULL`, `reference_id NULL`, `reverses_id NULL`, `occurred_at`, `created_by`, `notes NULL`.

#### inventory_movement_items
`movement_id`, `item_id`, `location_id NULL`, `quantity qty` (assinada: entrada +, saída −), `unit_cost price`.

#### stock_reservations
`warehouse_id`, `item_id`, `project_id NULL`, `quantity qty`, `status enum(active,consumed,released)`, `expires_at NULL`.

#### stock_adjustments
`warehouse_id`, `reason enum(count,loss,damage,correction)`, `movement_id`, `approved_by NULL`.

### 2.14 Equipes e equipamentos

Empregado ≠ usuário (D-11).

#### employees  (SD)
`uuid`, `company_id`, `user_id NULL`, `registration_number`, `name`, `document_number`, `role_title`, `hired_at`, `terminated_at NULL`, `status`. `UNIQUE (tenant_id, company_id, registration_number)`. Dados pessoais sensíveis (LGPD).

#### employee_documents
`employee_id`, `document_id`, `kind`, `expires_at NULL`.

#### employee_certifications
`employee_id`, `name`, `issued_at`, `expires_at NULL`, `document_id NULL`.

#### labor_allocations
`employee_id`, `project_id`, `schedule_task_id NULL`, `started_on`, `ended_on NULL`, `hourly_cost price NULL`.

#### work_shifts
`company_id`, `name`, `start_time`, `end_time`, `break_minutes`.

#### attendance_records
`employee_id`, `project_id`, `work_date`, `shift_id NULL`, `check_in NULL`, `check_out NULL`, `status enum(present,absent,justified,vacation)`. `UNIQUE (tenant_id, employee_id, work_date)`.

#### equipment  (SD)
`company_id`, `code`, `name`, `kind`, `ownership enum(owned,rented)`, `supplier_id NULL`, `hourly_cost price NULL`, `status enum(available,in_use,maintenance,retired)`.

#### equipment_allocations
`equipment_id`, `project_id`, `started_on`, `ended_on NULL`.

#### equipment_maintenance
`equipment_id`, `kind enum(preventive,corrective)`, `performed_on`, `cost money`, `next_due NULL`, `notes NULL`.

### 2.15 Documentos

Arquivos em **Object Storage S3**; o banco guarda metadados.

#### documents
`uuid`, `category_id NULL`, `title`, `current_version_id NULL`, `status enum(active,archived)`, `visibility enum(private,restricted)`, `created_by`.

#### document_versions  (append-only)
`document_id`, `version_number int`, `storage_disk`, `storage_key str(500)`, `original_name`, `mime_type`, `size_bytes bigint`, `sha256 char(64)`, `uploaded_by`. `UNIQUE (tenant_id, document_id, version_number)`. **Sem URL pública permanente**: acesso por URL assinada de curta duração.

#### document_categories
`parent_id NULL`, `code`, `name`.

#### document_links
`document_id`, `linkable_type`, `linkable_id`. `UNIQUE (tenant_id, document_id, linkable_type, linkable_id)`. Polimórfica.

#### document_permissions
`document_id`, `subject_type enum(user,role,project)`, `subject_id`, `ability enum(view,download,edit,manage)`.

### 2.16 Auditoria, notificações e logs

#### audit_logs  (append-only, G com `tenant_id NULL` para eventos de plataforma)
`id`, `uuid`, `tenant_id NULL`, `user_id NULL`, `event str(60)` (catálogo `dominio.acao`: `auth.*`, `tenant.*`, `invitation.*`, `membership.*`, `role.*`, `permission.*`, `token.*` — ver [FASE1_MODELO.md](FASE1_MODELO.md) §6; demais domínios acrescentam os seus), `entity_type`, `entity_id`, `old_values json NULL`, `new_values json NULL`, `metadata json NULL`, `ip_address varbinary(16)`, `user_agent str(255)`, `request_id char(36)`, `created_at timestamp(6)`. Índices `(tenant_id, entity_type, entity_id)`, `(tenant_id, created_at)`. Sem `updated_at`. **Segredos/senhas/tokens mascarados antes de gravar.**

#### notifications
`user_id`, `type`, `data json`, `read_at NULL`.

#### notification_preferences
`user_id`, `channel enum(in_app,email)`, `type`, `enabled bool`. `UNIQUE (tenant_id, user_id, channel, type)`.

#### user_activity_logs
`user_id`, `action`, `route NULL`, `occurred_at`. *Opcional; retenção curta.*

#### integration_logs
`integration str(60)`, `direction enum(inbound,outbound)`, `status`, `request_summary json`, `response_status int NULL`, `duration_ms int`. **Sem payloads com segredos.**

### 2.17 Inteligência artificial

#### ai_conversations
`uuid`, `user_id`, `context_type NULL`, `context_id NULL`, `title NULL`.

#### ai_messages
`conversation_id`, `role enum(user,assistant,system)`, `content mediumtext`, `redaction_applied bool`.

#### ai_usage_logs
`user_id`, `model str(80)`, `input_tokens int`, `output_tokens int`, `cost_estimate money NULL`, `feature str(60)`.

#### ai_generated_suggestions
`uuid`, `conversation_id NULL`, `user_id`, `target_type`, `target_id NULL`, `payload json`, `status enum(proposed,accepted,rejected,expired)`, `reviewed_by NULL`, `reviewed_at NULL`, `origin_model str(80)`. **Nunca aplicada automaticamente**: `accepted` exige `reviewed_by`.

#### ai_feedback
`suggestion_id`, `user_id`, `rating tinyint`, `comment NULL`.

### 2.18 Infraestrutura Laravel

#### failed_jobs
Tabela padrão do Laravel (filas em Redis; falhas persistidas). Se `uuid`/`payload` contiverem `tenant_id`, não é chave estrangeira.

## 3. Inventário

O total de tabelas propostas está no relatório da tarefa e é conferível com `grep -c '^#### ' DATABASE.md` (cada tabela é um cabeçalho `####`).

## 4. Integridade e isolamento (resumo)

- `tenant_id NOT NULL` em toda tabela operacional; FKs **compostas** `(tenant_id, x_id)` entre entidades do mesmo tenant.
- Exceções documentadas (D-13/D-14): tabelas **polimórficas** e referências a catálogos **globais** não têm FK composta; o isolamento é garantido por `BelongsToTenant`, regras de validação `tenant-scoped` e testes de acesso cruzado.
- Exclusão: `RESTRICT` por padrão; `CASCADE` apenas em linhas-filhas de rascunho. Registros financeiros/históricos nunca são apagados fisicamente. Detalhes em [DB_CONSTRAINTS.md](DB_CONSTRAINTS.md).

## 5. Evolução

Migrations incrementais e revisadas; nenhuma alteração manual em produção; seeders apenas para desenvolvimento/testes/demonstração, nunca misturados a dados reais. Ordem em [DB_MIGRATION_PLAN.md](DB_MIGRATION_PLAN.md).
