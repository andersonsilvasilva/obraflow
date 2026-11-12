# DATA_DICTIONARY.md — DICIONÁRIO DE DADOS

> Proposta para aprovação. As **colunas e tipos de cada tabela** estão em [DATABASE.md](DATABASE.md) (fonte única, para evitar divergência). Este documento define o **vocabulário**: tipos, convenções, domínios de valores (enums) e a finalidade de cada tabela.

## 1. Tipos lógicos

| Tipo | SQL | Observação |
|---|---|---|
| `pk` | `BIGINT UNSIGNED AUTO_INCREMENT` | chave interna |
| `fk` | `BIGINT UNSIGNED` | referência; composta com `tenant_id` quando possível |
| `uuid` | `CHAR(36)` | identificador público |
| `money` | `DECIMAL(15,2)` | valor monetário |
| `price` | `DECIMAL(15,4)` | preço unitário |
| `qty` | `DECIMAL(18,4)` | quantidade |
| `coef` | `DECIMAL(18,6)` | coeficiente |
| `pct` | `DECIMAL(9,4)` | percentual (25 = 25%) |
| `ts` | `TIMESTAMP` UTC | |
| `date` | `DATE` | sem fuso |
| `json` | `JSON` | só para estruturas variáveis (snapshots, componentes) |
| `enum(...)` | `VARCHAR` + `CHECK`/enum PHP | preferir `VARCHAR` + enum no PHP para evoluir sem `ALTER` |

## 2. Domínios de valores (enums)

| Enum | Valores |
|---|---|
| `tenants.status` | trial, active, suspended, canceled |
| `tenant_users.membership_status` | active, suspended, removed |
| `user_invitations.status` | pending, accepted, expired, revoked |
| `users.status` | active, blocked |
| `subscriptions.status` | trialing, active, past_due, canceled, expired |
| `projects.status` | planning, in_progress, paused, delayed, completed, canceled |
| `budgets.mode` | customer, manual, reference |
| `budgets.status` / `budget_versions.status` | draft, in_review, approved, rejected, (archived / superseded) |
| `*.source` / `price_origin` | official, custom, negotiated, (manual) |
| `budgets.social_charges_regime` | with_relief, without_relief, not_applicable |
| `contracts.kind` | customer, supplier, service |
| `contracts.status` | draft, active, suspended, completed, canceled |
| `measurements.kind` | physical, contractual |
| `purchase_requests.status` | draft, pending, approved, rejected, quoting, ordered, received, canceled |
| `purchase_requests.priority` | low, medium, high, urgent |
| `accounts_*.status` | open, partial, paid, canceled |
| `financial_transactions.kind` | payment, receipt, transfer_in, transfer_out, adjustment, reversal |
| `inventory_movements.kind` | receipt, issue, transfer, adjustment, return, reversal |
| `ai_generated_suggestions.status` | proposed, accepted, rejected, expired |
| `audit_logs.event` | Formato `dominio.acao`. Fase 1: `auth.login`, `auth.login_failed`, `auth.logout`, `auth.password_reset_requested`, `auth.password_changed`, `tenant.selected`, `tenant.selection_denied`, `tenant.updated`, `tenant.suspended`, `tenant.reactivated`, `invitation.created`, `invitation.accepted`, `invitation.revoked`, `invitation.expired`, `membership.created`, `membership.suspended`, `membership.reactivated`, `membership.removed`, `role.created`, `role.updated`, `role.deleted`, `role.assigned`, `role.revoked`, `permission.granted`, `permission.revoked`, `token.created`, `token.revoked`, `token.expired_use_denied`. Demais domínios acrescentam os seus |

## 3. Finalidade das tabelas

| Domínio | Tabela | Finalidade |
|---|---|---|
| SaaS | `tenants` | Conta organizacional contratante |
| | `tenant_users` | Vínculo usuário↔tenant (membership) |
| | `tenant_domains` / `tenant_settings` | Domínios e parâmetros do tenant |
| | `plans`, `plan_features` | Catálogo comercial e limites (sem preços fixados) |
| | `subscriptions`, `subscription_items`, `subscription_usage` | Assinatura, itens contratados e consumo |
| | `invoices`, `payments` | Cobrança do SaaS (sem dados de cartão) |
| Acesso | `users` | Identidade global de login |
| | `user_companies`, `user_project_access` | Escopo de empresa e de obra por usuário |
| | `roles`, `permissions`, `model_has_*`, `role_has_permissions` | Spatie (Teams = tenant) |
| | `personal_access_tokens` | Tokens Sanctum (+`tenant_id`) |
| | `user_invitations`, `password_reset_tokens` | Convites e recuperação (somente hashes) |
| Cadastros | `companies`, `company_branches` | Pessoas jurídicas e filiais do tenant |
| | `customers`, `suppliers` | Clientes e fornecedores |
| | `contacts`, `addresses` | Contatos e endereços (polimórficos) |
| Obras | `projects` e filhas | Obra, equipe, fases, configurações, histórico de situação |
| Contratos | `contracts` e filhas | Contrato, partes, itens, aditivos, documentos, histórico |
| Orçamentos | `budgets`, `budget_versions` | Orçamento e suas versões |
| | `budget_groups`, `budget_items` | Estrutura e itens (snapshot) |
| | `budget_compositions`, `budget_composition_items` | Composições instanciadas e seus insumos |
| | `budget_resources`, `budget_resource_items` | Insumos consolidados e rastreio |
| | `budget_costs`, `budget_indirect_costs`, `budget_bdi` | Totais, indiretos e BDI |
| | `budget_approvals`, `budget_status_history`, `budget_imports` | Aprovação, histórico e importações |
| | `units_of_measure` | Unidades de medida |
| Referências | `reference_*` | Bases técnicas importadas (fonte, competência, localidade, versão) |
| Planejamento | `schedules*` | Cronograma, dependências, marcos, linha de base, avanço |
| Diário | `construction_diaries*` | Registro diário por obra |
| Medições | `measurements*` | Medições físicas/contratuais com aprovação e histórico |
| Financeiro | `financial_*`, `accounts_*`, `cost_centers`, `payment_methods`, `cash_flow_entries` | Títulos, movimentações, rateio, conciliação, fluxo de caixa |
| Compras | `purchase_*`, `supplier_quote*`, `goods_receipt*` | Solicitação→Aprovação→Cotação→Pedido→Recebimento |
| Estoque | `warehouses*`, `inventory_*`, `stock_*` | Depósitos, saldos (derivados), movimentos, reservas, ajustes |
| Equipes | `employees*`, `labor_allocations`, `work_shifts`, `attendance_records`, `equipment*` | Pessoal operacional e equipamentos |
| Documentos | `documents`, `document_*` | Metadados, versões, vínculos e permissões (arquivo no S3) |
| Transversais | `audit_logs`, `notifications`, `notification_preferences`, `user_activity_logs`, `integration_logs` | Auditoria, avisos e logs |
| IA | `ai_*` | Conversas, mensagens, uso, sugestões e feedback |

## 4. Dados sensíveis (LGPD)

| Dado | Onde | Tratamento |
|---|---|---|
| Senha | `users.password` | Hash (bcrypt/argon); nunca em log |
| Tokens | `personal_access_tokens`, `user_invitations`, `password_reset_tokens` | Somente hash |
| Documento/CPF/CNPJ | `customers`, `suppliers`, `employees` | Acesso restrito; mascarar em logs/exports |
| Dados de empregados | `employees*`, `attendance_records` | Permissão específica; auditar leitura em massa |
| Conteúdo de IA | `ai_messages` | Redação antes do envio ao provedor; retenção limitada |
| IP/User-Agent | `audit_logs` | Finalidade de segurança; retenção definida (D-P06/P07) |
