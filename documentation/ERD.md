# ERD.md — DIAGRAMAS ENTIDADE-RELACIONAMENTO

> Proposta para aprovação. Diagramas **por domínio** (legíveis) + um diagrama geral simplificado. Colunas mostradas são apenas as relevantes para os relacionamentos; o detalhe completo está em [DATABASE.md](DATABASE.md).
> Convenção: toda tabela operacional possui `tenant_id` e FKs **compostas** `(tenant_id, x_id)`; nos diagramas, `tenant_id` é omitido das relações para legibilidade, exceto quando o ponto é o isolamento.

## 0. Visão geral simplificada

```mermaid
erDiagram
    TENANTS ||--o{ TENANT_USERS : "membros"
    USERS ||--o{ TENANT_USERS : "participa"
    TENANTS ||--o{ COMPANIES : possui
    COMPANIES ||--o{ PROJECTS : executa
    CUSTOMERS ||--o{ PROJECTS : contrata
    PROJECTS ||--o{ BUDGETS : orcada_por
    BUDGETS ||--o{ BUDGET_VERSIONS : versoes
    PROJECTS ||--o{ CONTRACTS : regida_por
    PROJECTS ||--o{ SCHEDULES : planejada_em
    PROJECTS ||--o{ CONSTRUCTION_DIARIES : registra
    PROJECTS ||--o{ MEASUREMENTS : mede
    PROJECTS ||--o{ PURCHASE_REQUESTS : demanda
    PROJECTS ||--o{ ACCOUNTS_PAYABLE : custos
    TENANTS ||--o{ DOCUMENTS : armazena
    TENANTS ||--o{ AUDIT_LOGS : audita
```

## 1. SaaS e multi-tenancy

```mermaid
erDiagram
    TENANTS ||--o{ TENANT_DOMAINS : "domínios"
    TENANTS ||--o{ TENANT_SETTINGS : "configurações"
    TENANTS ||--o{ SUBSCRIPTIONS : assina
    PLANS ||--o{ PLAN_FEATURES : define
    PLANS ||--o{ SUBSCRIPTIONS : "plano de"
    SUBSCRIPTIONS ||--o{ SUBSCRIPTION_ITEMS : itens
    SUBSCRIPTIONS ||--o{ SUBSCRIPTION_USAGE : consumo
    SUBSCRIPTIONS ||--o{ INVOICES : fatura
    INVOICES ||--o{ PAYMENTS : "pago por"
    TENANTS ||--o{ TENANT_USERS : "membership"
    USERS ||--o{ TENANT_USERS : "membership"

    TENANTS { bigint id PK
        string slug UK
        string status
        string timezone }
    TENANT_USERS { bigint id PK
        bigint tenant_id FK
        bigint user_id FK
        string membership_status }
    USERS { bigint id PK
        string email UK
        string status }
```

`TENANT_USERS` tem `UNIQUE (tenant_id, user_id)`. `USERS` **não** possui `tenant_id`.

## 2. Usuários e permissões

```mermaid
erDiagram
    USERS ||--o{ TENANT_USERS : ""
    USERS ||--o{ USER_COMPANIES : "acessa empresa"
    USERS ||--o{ USER_PROJECT_ACCESS : "acessa obra"
    USERS ||--o{ PERSONAL_ACCESS_TOKENS : tokens
    USERS ||--o{ USER_INVITATIONS : convida
    COMPANIES ||--o{ USER_COMPANIES : ""
    PROJECTS ||--o{ USER_PROJECT_ACCESS : ""
    ROLES ||--o{ ROLE_HAS_PERMISSIONS : ""
    PERMISSIONS ||--o{ ROLE_HAS_PERMISSIONS : ""
    ROLES ||--o{ MODEL_HAS_ROLES : "atribuído"
    PERMISSIONS ||--o{ MODEL_HAS_PERMISSIONS : "atribuída"
    TENANTS ||--o{ ROLES : "tenant_id = team"

    MODEL_HAS_ROLES { bigint role_id PK
        bigint tenant_id PK "team_foreign_key"
        string model_type PK
        bigint model_id PK }
    PERSONAL_ACCESS_TOKENS { bigint tenant_id "nullable (D-05)" }
```

### 2.1 Fase 1 — modelo definitivo (FKs compostas)

```mermaid
erDiagram
    USERS ||--o{ TENANT_USERS : "vínculo"
    TENANTS ||--o{ TENANT_USERS : "vínculo"
    TENANTS ||--o{ TENANT_DOMAINS : domínios
    TENANTS ||--o{ TENANT_SETTINGS : parâmetros
    TENANTS ||--o{ ROLES : "papéis do tenant (NOT NULL)"
    ROLES ||--o{ MODEL_HAS_ROLES : "FK composta (tenant_id, role_id)"
    TENANT_USERS ||--o{ MODEL_HAS_ROLES : "FK composta (tenant_id, user_id)"
    TENANT_USERS ||--o{ MODEL_HAS_PERMISSIONS : "FK composta (tenant_id, user_id)"
    PERMISSIONS ||--o{ MODEL_HAS_PERMISSIONS : concede
    ROLES ||--o{ ROLE_HAS_PERMISSIONS : contém
    PERMISSIONS ||--o{ ROLE_HAS_PERMISSIONS : ""
    TENANT_USERS ||--o{ PERSONAL_ACCESS_TOKENS : "FK composta (tenant_id, user_id)"
    TENANTS ||--o{ USER_INVITATIONS : convites
    ROLES ||--o{ USER_INVITATIONS : "FK composta (tenant_id, role_id)"
    TENANT_USERS ||--o{ USER_INVITATIONS : "convidante / aceitante"
    TENANTS |o--o{ AUDIT_LOGS : "NULL = fora de tenant"
    USERS |o--o{ AUDIT_LOGS : ator
```

Substitui, para a Fase 1, o diagrama da seção anterior. Detalhes e validação: [FASE1_MODELO.md](FASE1_MODELO.md).

## 3. Empresas e obras

```mermaid
erDiagram
    TENANTS ||--o{ COMPANIES : ""
    COMPANIES ||--o{ COMPANY_BRANCHES : filiais
    COMPANIES ||--o{ CUSTOMERS : ""
    COMPANIES ||--o{ SUPPLIERS : ""
    COMPANIES ||--o{ PROJECTS : ""
    CUSTOMERS ||--o{ PROJECTS : ""
    PROJECTS ||--o{ PROJECT_MEMBERS : equipe
    PROJECTS ||--o{ PROJECT_SETTINGS : ""
    PROJECTS ||--o{ PROJECT_PHASES : fases
    PROJECTS ||--o{ PROJECT_STATUS_HISTORY : histórico
    PROJECTS ||--o{ PROJECT_DOCUMENTS : ""
    DOCUMENTS ||--o{ PROJECT_DOCUMENTS : ""
    EMPLOYEES ||--o{ PROJECTS : "responsável técnico"
    CUSTOMERS ||--o{ CONTACTS : "polimórfico"
    CUSTOMERS ||--o{ ADDRESSES : "polimórfico"
    SUPPLIERS ||--o{ CONTACTS : "polimórfico"
```

## 4. Contratos

```mermaid
erDiagram
    PROJECTS ||--o{ CONTRACTS : ""
    COMPANIES ||--o{ CONTRACTS : ""
    CONTRACTS ||--o{ CONTRACT_PARTIES : partes
    CONTRACTS ||--o{ CONTRACT_ITEMS : itens
    CONTRACTS ||--o{ CONTRACT_ADDENDA : aditivos
    CONTRACTS ||--o{ CONTRACT_DOCUMENTS : ""
    CONTRACTS ||--o{ CONTRACT_STATUS_HISTORY : histórico
    DOCUMENTS ||--o{ CONTRACT_DOCUMENTS : ""
    BUDGET_ITEMS |o--o{ CONTRACT_ITEMS : "vínculo opcional"
```

## 5. Orçamentos e composições

```mermaid
erDiagram
    BUDGETS ||--o{ BUDGET_VERSIONS : versões
    BUDGET_VERSIONS ||--o{ BUDGET_GROUPS : etapas
    BUDGET_GROUPS ||--o{ BUDGET_ITEMS : itens
    BUDGET_VERSIONS ||--o{ BUDGET_ITEMS : ""
    BUDGET_ITEMS ||--o| BUDGET_COMPOSITIONS : "composição instanciada"
    BUDGET_COMPOSITIONS ||--o{ BUDGET_COMPOSITION_ITEMS : insumos
    BUDGET_VERSIONS ||--o{ BUDGET_RESOURCES : "insumos consolidados"
    BUDGET_RESOURCES ||--o{ BUDGET_RESOURCE_ITEMS : rastreia
    BUDGET_ITEMS ||--o{ BUDGET_RESOURCE_ITEMS : ""
    BUDGET_VERSIONS ||--o{ BUDGET_COSTS : totais
    BUDGET_VERSIONS ||--o{ BUDGET_INDIRECT_COSTS : indiretos
    BUDGET_VERSIONS ||--o{ BUDGET_BDI : bdi
    BUDGET_VERSIONS ||--o{ BUDGET_APPROVALS : aprovações
    BUDGETS ||--o{ BUDGET_STATUS_HISTORY : histórico
    BUDGETS ||--o{ BUDGET_IMPORTS : importações

    BUDGET_ITEMS { decimal unit_cost "DECIMAL(15,4) — cópia"
        string source "official|custom|negotiated|manual"
        bigint source_composition_id "sem FK (snapshot)" }
    BUDGET_VERSIONS { timestamp locked_at "aprovada = imutável" }
```

Itens e insumos **copiam** código/descrição/unidade/coeficiente/preço (snapshot). `source_*_id` aponta para a referência apenas como rastreabilidade, **sem FK**.

## 6. Referências técnicas

```mermaid
erDiagram
    REFERENCE_SOURCES ||--o{ REFERENCE_DATASETS : publica
    REFERENCE_DATASETS ||--o{ REFERENCE_COMPETENCIES : competências
    REFERENCE_DATASETS ||--o{ REFERENCE_LOCATIONS : localidades
    REFERENCE_DATASETS ||--o{ REFERENCE_RESOURCES : insumos
    REFERENCE_DATASETS ||--o{ REFERENCE_COMPOSITIONS : composições
    REFERENCE_RESOURCES ||--o{ REFERENCE_RESOURCE_PRICES : preços
    REFERENCE_COMPETENCIES ||--o{ REFERENCE_RESOURCE_PRICES : ""
    REFERENCE_LOCATIONS ||--o{ REFERENCE_RESOURCE_PRICES : ""
    REFERENCE_COMPOSITIONS ||--o{ REFERENCE_COMPOSITION_ITEMS : itens
    REFERENCE_RESOURCES |o--o{ REFERENCE_COMPOSITION_ITEMS : ""
    TENANTS |o--o{ REFERENCE_DATASETS : "NULL = oficial global"

    REFERENCE_RESOURCE_PRICES { string price_origin "official|custom|negotiated" }
```

## 7. Planejamento e diário de obras

```mermaid
erDiagram
    PROJECTS ||--o{ SCHEDULES : ""
    SCHEDULES ||--o{ SCHEDULE_TASKS : tarefas
    SCHEDULE_TASKS ||--o{ SCHEDULE_DEPENDENCIES : predecessora
    SCHEDULE_TASKS ||--o{ SCHEDULE_DEPENDENCIES : sucessora
    SCHEDULES ||--o{ SCHEDULE_MILESTONES : marcos
    SCHEDULES ||--o{ SCHEDULE_BASELINES : "linhas de base"
    SCHEDULE_TASKS ||--o{ SCHEDULE_PROGRESS_HISTORY : avanço
    PROJECTS ||--o{ CONSTRUCTION_DIARIES : ""
    CONSTRUCTION_DIARIES ||--o{ CONSTRUCTION_DIARY_ACTIVITIES : ""
    CONSTRUCTION_DIARIES ||--o{ CONSTRUCTION_DIARY_OCCURRENCES : ""
    CONSTRUCTION_DIARIES ||--o{ CONSTRUCTION_DIARY_TEAMS : ""
    CONSTRUCTION_DIARIES ||--o{ CONSTRUCTION_DIARY_EQUIPMENT : ""
    CONSTRUCTION_DIARIES ||--o{ CONSTRUCTION_DIARY_WEATHER : ""
    CONSTRUCTION_DIARIES ||--o{ CONSTRUCTION_DIARY_ATTACHMENTS : ""
    SCHEDULE_TASKS |o--o{ CONSTRUCTION_DIARY_ACTIVITIES : "executa"
    BUDGET_ITEMS |o--o{ SCHEDULE_TASKS : "vincula"
```

## 8. Medições

```mermaid
erDiagram
    PROJECTS ||--o{ MEASUREMENTS : ""
    CONTRACTS |o--o{ MEASUREMENTS : ""
    MEASUREMENTS ||--o{ MEASUREMENT_ITEMS : itens
    MEASUREMENTS ||--o{ MEASUREMENT_ATTACHMENTS : anexos
    MEASUREMENTS ||--o{ MEASUREMENT_APPROVALS : aprovações
    MEASUREMENTS ||--o{ MEASUREMENT_HISTORY : histórico
    CONTRACT_ITEMS |o--o{ MEASUREMENT_ITEMS : ""
    BUDGET_ITEMS |o--o{ MEASUREMENT_ITEMS : ""
    DOCUMENTS ||--o{ MEASUREMENT_ATTACHMENTS : ""
```

## 9. Financeiro

```mermaid
erDiagram
    COMPANIES ||--o{ FINANCIAL_ACCOUNTS : contas
    FINANCIAL_CATEGORIES ||--o{ ACCOUNTS_PAYABLE : classifica
    FINANCIAL_CATEGORIES ||--o{ ACCOUNTS_RECEIVABLE : classifica
    COST_CENTERS ||--o{ ACCOUNTS_PAYABLE : ""
    SUPPLIERS ||--o{ ACCOUNTS_PAYABLE : ""
    CUSTOMERS ||--o{ ACCOUNTS_RECEIVABLE : ""
    PROJECTS |o--o{ ACCOUNTS_PAYABLE : ""
    CONTRACTS |o--o{ ACCOUNTS_PAYABLE : ""
    ACCOUNTS_PAYABLE |o--o{ FINANCIAL_TRANSACTIONS : "liquidado por"
    ACCOUNTS_RECEIVABLE |o--o{ FINANCIAL_TRANSACTIONS : "liquidado por"
    FINANCIAL_ACCOUNTS ||--o{ FINANCIAL_TRANSACTIONS : ""
    FINANCIAL_TRANSACTIONS ||--o{ FINANCIAL_TRANSACTION_ITEMS : rateio
    FINANCIAL_TRANSACTIONS |o--o| FINANCIAL_TRANSACTIONS : "estorna (reverses_id)"
    PAYMENT_METHODS ||--o{ FINANCIAL_TRANSACTIONS : ""
    FINANCIAL_ACCOUNTS ||--o{ FINANCIAL_RECONCILIATIONS : concilia
    FINANCIAL_RECONCILIATIONS |o--o{ FINANCIAL_TRANSACTIONS : ""
    PROJECTS ||--o{ CASH_FLOW_ENTRIES : ""
```

## 10. Compras e estoque

```mermaid
erDiagram
    PROJECTS ||--o{ PURCHASE_REQUESTS : ""
    PURCHASE_REQUESTS ||--o{ PURCHASE_REQUEST_ITEMS : itens
    PURCHASE_REQUESTS ||--o{ PURCHASE_APPROVALS : aprovação
    PURCHASE_REQUESTS ||--o{ SUPPLIER_QUOTES : cotações
    SUPPLIERS ||--o{ SUPPLIER_QUOTES : ""
    SUPPLIER_QUOTES ||--o{ SUPPLIER_QUOTE_ITEMS : ""
    SUPPLIER_QUOTES |o--o{ PURCHASE_ORDERS : "origina"
    PURCHASE_ORDERS ||--o{ PURCHASE_ORDER_ITEMS : ""
    PURCHASE_ORDERS ||--o{ GOODS_RECEIPTS : recebimentos
    GOODS_RECEIPTS ||--o{ GOODS_RECEIPT_ITEMS : ""
    WAREHOUSES ||--o{ WAREHOUSE_LOCATIONS : ""
    WAREHOUSES ||--o{ INVENTORY_BALANCES : ""
    INVENTORY_ITEMS ||--o{ INVENTORY_BALANCES : ""
    WAREHOUSES ||--o{ INVENTORY_MOVEMENTS : ""
    INVENTORY_MOVEMENTS ||--o{ INVENTORY_MOVEMENT_ITEMS : ""
    INVENTORY_ITEMS ||--o{ INVENTORY_MOVEMENT_ITEMS : ""
    INVENTORY_ITEMS ||--o{ STOCK_RESERVATIONS : ""
    INVENTORY_MOVEMENTS ||--o| STOCK_ADJUSTMENTS : ""
    GOODS_RECEIPTS |o--o{ INVENTORY_MOVEMENTS : "gera entrada"
```

## 11. Equipes e equipamentos

```mermaid
erDiagram
    COMPANIES ||--o{ EMPLOYEES : ""
    USERS |o--o| EMPLOYEES : "opcional (≠ usuário)"
    EMPLOYEES ||--o{ EMPLOYEE_DOCUMENTS : ""
    EMPLOYEES ||--o{ EMPLOYEE_CERTIFICATIONS : ""
    EMPLOYEES ||--o{ LABOR_ALLOCATIONS : ""
    PROJECTS ||--o{ LABOR_ALLOCATIONS : ""
    EMPLOYEES ||--o{ ATTENDANCE_RECORDS : ""
    WORK_SHIFTS ||--o{ ATTENDANCE_RECORDS : ""
    COMPANIES ||--o{ EQUIPMENT : ""
    EQUIPMENT ||--o{ EQUIPMENT_ALLOCATIONS : ""
    PROJECTS ||--o{ EQUIPMENT_ALLOCATIONS : ""
    EQUIPMENT ||--o{ EQUIPMENT_MAINTENANCE : ""
```

## 12. Documentos e auditoria

```mermaid
erDiagram
    DOCUMENT_CATEGORIES ||--o{ DOCUMENTS : ""
    DOCUMENTS ||--o{ DOCUMENT_VERSIONS : versões
    DOCUMENTS ||--o{ DOCUMENT_LINKS : "vínculos polimórficos"
    DOCUMENTS ||--o{ DOCUMENT_PERMISSIONS : acesso
    USERS ||--o{ NOTIFICATIONS : ""
    USERS ||--o{ NOTIFICATION_PREFERENCES : ""
    TENANTS ||--o{ AUDIT_LOGS : "tenant_id (NULL = plataforma)"
    USERS |o--o{ AUDIT_LOGS : ""
    TENANTS ||--o{ INTEGRATION_LOGS : ""

    DOCUMENT_VERSIONS { string storage_key "S3 — sem URL pública"
        char sha256
        bigint size_bytes }
    AUDIT_LOGS { json old_values
        json new_values "segredos mascarados" }
```

## 13. Inteligência artificial

```mermaid
erDiagram
    USERS ||--o{ AI_CONVERSATIONS : ""
    AI_CONVERSATIONS ||--o{ AI_MESSAGES : ""
    AI_CONVERSATIONS |o--o{ AI_GENERATED_SUGGESTIONS : ""
    AI_GENERATED_SUGGESTIONS ||--o{ AI_FEEDBACK : ""
    USERS ||--o{ AI_USAGE_LOGS : ""

    AI_GENERATED_SUGGESTIONS { string status "proposed|accepted|rejected|expired"
        bigint reviewed_by "obrigatório para accepted" }
```
