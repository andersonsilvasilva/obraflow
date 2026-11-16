# FASE 3 — EQUIPES E OBRAS: MODELO APROVADO

> **Aprovado em 2026-11-16** (Fase 3 autorizada e decisões D-P19…D-P28 aprovadas pelo responsável). Etapas 1 a 3 executadas juntas, a pedido do responsável (§7). Base: [DATABASE.md](DATABASE.md) §2.2, §2.4, §2.14; [DB_MIGRATION_PLAN.md](DB_MIGRATION_PLAN.md) (Fase 3); padrões das Fases 1 e 2 ([FASE1_MODELO.md](FASE1_MODELO.md), [FASE2_MODELO.md](FASE2_MODELO.md)); telas de referência 15–19 e planilhas `employee.xlsx`/`equipment.xlsx` (só os cabeçalhos foram lidos) — [SCREEN_REFERENCES.md](SCREEN_REFERENCES.md).

## 1. Escopo (ROADMAP)

Funcionários, equipamentos, obras, membros da obra, fases da obra e acesso por obra.
**Fora:** orçamentos e custos (Fase 4 — inclui preço de cargo, salário, encargos, custo horário de equipamento); alocação de mão de obra/equipamento, ponto e turnos (sem consumidor nesta fase); contratos; importação de planilhas; telas (etapa própria, já no novo padrão de layout).

## 2. Tabelas propostas (13 novas + 1 ajuste)

Mesmas garantias da Fase 2: `id`, `tenant_id NOT NULL`, `created_at`, `updated_at`, `UNIQUE (tenant_id, id)`, FKs **compostas** `(tenant_id, x_id)` todas `RESTRICT`, CHECKs no banco, sem triggers, `BelongsToTenant` fail-closed, soft delete com `active_marker` no índice único (D-P13), auditoria na mesma transação. `SD` = soft delete.

| # | Tabela | Colunas principais | Unicidade / regras |
|---|---|---|---|
| 1 | `job_titles` (cargo/função) | `name`, `status` | `(tenant_id, name)` |
| 2 | `departments` (setor) | `name`, `status` | `(tenant_id, name)` |
| 3 | `resource_groups` | `kind` (`equipment`; `material` na Fase 4), `parent_id` NULL (subgrupo, 2 níveis), `name` | `(tenant_id, kind, parent_id, name)` |
| 4 | `employees` (SD) | `uuid`, `company_id`, `job_title_id` NULL, `department_id` NULL, `supervisor_id` NULL (→ `employees`), `user_id` NULL (→ `tenant_users`), `registration_number` NULL (matrícula), `name`, `document_number` NULL (CPF, só dígitos, validado), `phone`, `email`, `labor_type` (`direct`/`indirect`), `contract_type` (`monthly`/`hourly`/`piecework`/`daily`), `hired_at` NULL, `terminated_at` NULL, `status` | matrícula e CPF únicos por `(tenant_id, company_id)` ativo; `user_id` único ativo; `supervisor_id ≠ id` |
| 5 | `employee_certifications` | `employee_id`, `name`, `issued_at`, `expires_at` NULL, `document_id` NULL (→ `documents`) | `expires_at ≥ issued_at` |
| 6 | `equipment` (SD) | `uuid`, `company_id`, `group_id` NULL (→ `resource_groups` kind equipment), `code` NULL, `name`, `brand`, `model`, `kind`, `condition`, `unit_id` NULL (→ `units_of_measure`), `main_supplier_id` NULL (→ `suppliers`), `ownership` (`owned`/`rented`), `status` (`available`/`in_use`/`maintenance`/`retired`) | `code` único por `(tenant_id, company_id)` ativo |
| 7 | `projects` (SD) — obras | `uuid`, `company_id`, `customer_id` NULL, `code`, `name`, `description`, `technical_manager_id` NULL (→ `employees`), `contract_value` DECIMAL NULL, `status` (`planning`/`in_progress`/`paused`/`delayed`/`completed`/`canceled`), `progress` (0–100, informado), `start_date`, `expected_end_date`, `actual_end_date` NULL, `created_by` | `(tenant_id, code)` ativo; datas coerentes por CHECK; cliente da **mesma empresa** |
| 8 | `project_addresses` | endereço da obra (colunas de endereço da Fase 2, `kind = site`) | 1 endereço principal por obra |
| 9 | `project_members` | `project_id`, `employee_id`, `role` (função na obra), `started_on`, `ended_on` NULL | 1 vínculo ativo por `(project, employee)`; `ended_on ≥ started_on` |
| 10 | `project_phases` | `project_id`, `parent_id` NULL (mesma obra, FK composta), `name`, `sort_order`, `planned_start`, `planned_end` | até 3 níveis (aplicação); datas coerentes |
| 11 | `project_status_history` (append-only) | `project_id`, `from_status` NULL, `to_status`, `changed_by`, `reason`, `changed_at` | imutável (como `audit_logs`) |
| 12 | `user_project_access` | `user_id` (→ `tenant_users`), `project_id`, `access_level` (`view`/`edit`/`manage`), `granted_by` | `(tenant_id, user_id, project_id)` |
| 13 | `user_companies` (D-P16) | `user_id` (→ `tenant_users`), `company_id`, `status` | `(tenant_id, user_id, company_id)` — ver D-P25 |
| ± | `document_links` (ajuste) | CHECK de `linkable_type` passa a aceitar `employee`, `equipment`, `project` | migration nova; a da Fase 2 não é alterada |

Não entram (do plano original): `employee_documents` e `project_documents` (substituídas por `document_links` — D-P19), `project_settings` e `work_shifts` (sem consumidor — D-P26).

## 3. Decisões (todas aprovadas conforme a recomendação, 2026-11-16)

| ID | Decisão | Recomendação |
|---|---|---|
| **D-P19** | **Documentos de funcionário e de obra:** tabelas dedicadas (`employee_documents`, `project_documents`) ou reaproveitar `document_links` da Fase 2 (ampliando o CHECK)? | **Reaproveitar `document_links`**: um só mecanismo de vínculo, permissão e URL assinada; vencimento fica em `employee_certifications` |
| **D-P20** | **Cargo/Função e Setor:** texto livre (`role_title`, como no DATABASE.md) ou cadastros (`job_titles`, `departments`), como nas telas e na planilha? | **Cadastros**, sem preço (o "Preço (R$)" da tela é custo de orçamento — Fase 4). Cadastro rápido no formulário (padrão F4) |
| **D-P21** | **Dados pessoais do funcionário (LGPD).** A planilha tem RG, sexo, nascimento, banco, agência, conta, PIX e adiantamento | **Minimizar:** guardar só nome, CPF (opcional, validado), matrícula, telefone, e-mail, cargo, setor, responsável, tipo de mão de obra e de contrato, datas. **Não** guardar RG, sexo, nascimento nem dados bancários/PIX nesta fase (pertencem a RH/financeiro, com base legal). CPF **mascarado** na API para quem não tem `employees.manage` |
| **D-P22** | **Grupo/subgrupo de equipamentos:** texto livre ou cadastro compartilhado `resource_groups` (reaproveitado pelos materiais da Fase 4)? | **`resource_groups`** com `kind` e 2 níveis |
| **D-P23** | **Responsável técnico da obra:** funcionário (`employees`, como no DATABASE.md) ou usuário? | **Funcionário** (empregado ≠ usuário, D-11); o funcionário pode estar ligado a um usuário |
| **D-P24** | **Acesso por obra.** Quem vê quais obras? | `projects.manage` vê e administra **todas**; os demais veem **só as obras em `user_project_access`**, e o nível (view/edit/manage) **limita** o que a permissão permite (interseção, como em documentos). Obra sem acesso ⇒ **404** |
| **D-P25** | **`user_companies`** (D-P16 adiou para esta fase): restringir usuários por empresa? | **Criar a tabela, mas sem efeito de bloqueio** até o tenant ter mais de uma empresa com equipes separadas; ligar a restrição exige decisão própria (evita travar os usuários atuais). Alternativa: adiar de novo |
| **D-P26** | `project_settings` e `work_shifts` (no plano, mas sem nenhum uso nesta fase) | **Adiar** para quando houver consumidor (configurações de obra / ponto) |
| **D-P27** | **Progresso da obra:** informado ou calculado? | **Informado** (0–100) agora; calculado quando houver cronograma/medição. **Mudança de status** gera `project_status_history` + auditoria; transições permitidas definidas na aplicação |
| **D-P28** | **Permissões novas** e papéis | `employees.view/manage`, `equipment.view/manage`, `projects.view/manage`. Cargos, setores e grupos sob `employees.manage`/`equipment.manage`. Papel **`member` de tenants novos**: `projects.view` e `equipment.view` (**não** `employees.view`, por dados pessoais). Papéis da HighTech **só mudam com sua autorização** (`obraflow:system-roles:sync`) |

## 4. API (resumo)

Rotas em `routes/api/v1/teams.php` e `projects.php` sob `tenant.required`, mesmo padrão da Fase 2: Form Requests escopados ao tenant (empresa/cliente/funcionário/fornecedor validados no tenant e, quando cabível, na **mesma empresa** — AGENTS.md §2), Policies, uuid público, 404 idêntico para inexistente × outro tenant × obra sem acesso, `error.code`/`error.details`, paginação e filtros por query string, auditoria de todas as mutações (eventos novos `team.*`, `project.*`, `project.access.*`). Compatível com o frontend React e com o padrão de layout (listagem com busca, filtros, ações por linha e paginação).

## 5. Etapas (após aprovação)

1. Cadastros auxiliares (`job_titles`, `departments`, `resource_groups`) + `employees` + `employee_certifications`.
2. `equipment`.
3. `projects`, `project_addresses`, `project_phases`, `project_status_history`, `project_members`.
4. Acesso por obra (`user_project_access`, `user_companies`) + ajuste de `document_links`.
5. API, permissões, Policies e auditoria.
6. Revisão estrutural do isolamento, CI em MySQL 8.0 e 8.4, relatório.

Cada etapa: testes de integridade (erros do próprio banco), isolamento cruzado, commit e CI — relato antes da seguinte. Migrations só em `obraflow_testing`; `migrate` no `obraflow` e qualquer mudança de papel da HighTech **somente com autorização**.

## 6. Riscos

- Dados pessoais de funcionários (LGPD, D-P07): minimização (D-P21) e CPF mascarado; política de retenção segue pendente.
- Acesso por obra muda a visibilidade: usuário sem linha em `user_project_access` não verá obras (intencional; conceder acesso faz parte da operação).
- `document_links` continua polimórfica (R4 da Fase 2), agora com 3 donos a mais.
- Importação das planilhas (`employee`, `equipment`, `product`) **não** está no escopo; exige etapa e aprovação próprias (colunas mapeadas na D-P21).

## 7. Andamento

### Etapas 1 a 3 (juntas, a pedido do responsável) — 11 tabelas, 11 modelos

`job_titles`, `departments`, `resource_groups`, `employees`, `employee_certifications`, `equipment`, `projects`, `project_addresses`, `project_phases`, `project_status_history`, `project_members` (migrations `2026_11_16_100001…100011`). Somente no `obraflow_testing`; o banco `obraflow` **não** foi migrado (continua com 33 migrations).

**Detalhes de implementação (garantias no banco além do proposto):**
- **Autoria é membro do tenant:** `projects.created_by` e `project_status_history.changed_by` têm FK composta → `tenant_users (tenant_id, user_id)` (mesmo padrão de `documents.created_by`); `employees.user_id` idem.
- **Responsável técnico da mesma empresa:** `employees` tem `UNIQUE (tenant_id, company_id, id)`, alvo da FK `(tenant_id, company_id, technical_manager_id)` de `projects`.
- **Grupo do tipo certo:** `resource_groups` tem `UNIQUE (tenant_id, kind, id)`; subgrupo usa FK `(tenant_id, kind, parent_id)` (pai do mesmo tipo) e `equipment` usa `group_kind` fixo por CHECK na FK `(tenant_id, group_kind, group_id)`. Coluna gerada `parent_key` torna o nome único também entre grupos raiz.
- **Fase-pai da mesma obra:** FK `(tenant_id, project_id, parent_id)` → `project_phases (tenant_id, project_id, id)`.
- **Um vínculo em aberto por funcionário e obra:** coluna gerada `open_marker` no índice único; vínculos encerrados ficam como histórico.
- **CHECKs:** domínios de status/tipos, CPF com 11 dígitos, progresso ≤ 100, valor ≥ 0, datas coerentes.

**Regras na aplicação (modelos):** responsável ≠ o próprio funcionário e da mesma empresa (CHECK não pode usar coluna AUTO_INCREMENT); cliente da obra da mesma empresa (`customers` é tabela homologada, sem o índice necessário); funcionário da equipe da mesma empresa da obra; grupos com 2 níveis e fases com 3 níveis, sem ciclo; `status` da obra **não** é preenchível (transições em `Project::TRANSITIONS`; o serviço que grava histórico + auditoria entra na etapa 5); `ProjectStatusHistory` append-only; CPF normalizado e máscara `***.982.247-**` pronta para a API.

**Testes:** `Feature/Database/TeamsProjectsIntegrityTest` (18 — erros 1452/3819/1062/1451 do próprio banco) e `Feature/Teams/TeamsProjectsModelsTest` (22). Suíte: **331/331 (1.862 asserções)**; revisão estrutural do isolamento (`IsolationStructureTest`) cobre as 11 tabelas novas automaticamente.

**Próximas:** etapa 4 (acesso por obra, `user_companies`, ajuste de `document_links`), etapa 5 (API, permissões, auditoria, serviço de status), etapa 6 (revisão, CI, relatório).