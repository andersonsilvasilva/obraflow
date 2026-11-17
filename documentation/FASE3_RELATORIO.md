# FASE 3 — EQUIPES E OBRAS: RELATÓRIO TÉCNICO

**Escopo:** cargos, setores, grupos de recursos, funcionários, certificações, equipamentos, obras (endereço, fases, histórico de status, equipe), acesso por obra, usuário × empresa e documentos de funcionário/equipamento/obra — modelo, API, permissões e auditoria. Sem telas (etapa própria, no padrão de layout de [SCREEN_REFERENCES.md](SCREEN_REFERENCES.md)).
**Modelo aprovado:** [FASE3_MODELO.md](FASE3_MODELO.md) (D-P19…D-P28, 2026-11-16). **Ambiente:** PHP 8.4.2 · Laravel 13.42 · MySQL 8.0.30 local; CI em MySQL 8.0 e 8.4.

## 1. Critérios de conclusão

| Critério | Estado | Evidência |
|---|---|---|
| 13 tabelas + ajuste de `document_links` com isolamento por FK composta | ✅ | `TeamsProjectsIntegrityTest` (21 — erros 1452/3819/1062/1451 do próprio banco) |
| Garantias além do proposto | ✅ | autoria membro do tenant (`created_by`, `changed_by`, `granted_by`, `user_id` → `tenant_users`); responsável técnico da mesma empresa (FK tripla); grupo do tipo certo; fase-pai da mesma obra; um vínculo de equipe em aberto |
| Modelos com escopo fail-closed e regras de aplicação | ✅ | `TeamsProjectsModelsTest` (22); `IsolationStructureTest` cobre as tabelas novas automaticamente |
| Acesso por obra (D-P24) | ✅ | `ProjectAccessTest` (5) e `ProjectsApiTest`: manage vê tudo; sem view nada; nível limita; 404 sem acesso; sem vazamento entre tenants |
| API com permissões e 404 entre tenants | ✅ | `TeamsApiTest` (10), `ProjectsApiTest` (12); `RouteAuthorizationTest` (3): as 46 rotas exigem tenant e `can:` do catálogo; escrita fora de obras exige `*.manage` |
| Dados pessoais (D-P21) | ✅ | CPF mascarado sem `employees.manage`; busca por CPF restrita; equipe sem CPF/contatos |
| Status da obra (D-P27) | ✅ | só transições permitidas; histórico append-only; auditoria com motivo; linha travada na transação |
| Auditoria de todas as mutações | ✅ | `registry.*` no CRUD; `project.status_changed`, `project.access_granted`, `project.access_revoked` com cobertura `AuditEvent::phase3()` |
| Tokens (D-29) | ✅ | testes de token em `TeamsApiTest` e `ProjectsApiTest` (sem `projects.manage` no token vale o acesso por obra) |
| CI em MySQL 8.0 e 8.4 | ✅ | etapas 1–5 verdes (§3) |
| Testes | ✅ | **Backend 364/364 (2.275 asserções)** · isolamento (`Registry`, `Teams`, `Tenancy`, `Database`) 213/213 · Pint e `composer audit` limpos |
| Banco `obraflow` (autorizado) | ✅ | 6 permissões instaladas (27 no catálogo); `member` da HighTech + `equipment.view`, `projects.view` (11, todas de consulta; 14º evento); **14 migrations da Fase 3 aplicadas** (47 no total; 13 tabelas vazias; CHECK de `document_links` com 7 tipos); `admin` da HighTech + 6 permissões (27; `permission.granted`, 15º evento). Segundas execuções: "nada a fazer". Nenhum dado de cadastro criado |

## 2. Etapas

| Etapa | Entrega | Commit | Testes |
|---|---|---|---|
| 1–3 (juntas, a pedido) | 11 tabelas e modelos (equipes, equipamentos, obras); padrão de layout; antimalware dispensado | `b026bd8` | 331 |
| 4 | Acesso por obra, usuário × empresa, ajuste de `document_links`, catálogo | `1dc6aa3` | 339 |
| 5 | API (46 rotas), status, acesso auditado, documentos, erros 422 | `e74f823` | 361 |
| 6 | Revisão estrutural de rotas, relatório, permissões no banco `obraflow` | este commit | 364 |

## 3. CI (GitHub Actions)

| Commit | Run | API MySQL 8.0 | API MySQL 8.4 | Web |
|---|---|---|---|---|
| `b026bd8` | 43955864771 | ✅ | ✅ | ✅ |
| `1dc6aa3` | 43957352378 | ✅ | ✅ | ✅ |
| `e74f823` | 43960398526 | ✅ | ✅ | ✅ |

## 4. Defeitos encontrados e corrigidos

1. **Autoria sem vínculo ao tenant:** `projects.created_by` apontava para `users` (global); passou a FK composta → `tenant_users`, como em `documents` (achado pelo primeiro teste de integridade).
2. **Regra de negócio virava 500:** exceções dos modelos passaram a `DomainRuleException` → 422 `domain_rule`.
3. **Edição parcial de datas:** enviar só a data final não era comparado com a inicial já gravada (o CHECK barrava com 500). Validação compara com o valor gravado; CHECK violado que escape vira 422 `constraint_violation`.
4. **Nível de acesso como atributo do modelo:** seria gravado no `UPDATE`; passou a propriedade do resource.
5. **`sed` corrompeu `use` em 5 modelos** (barras invertidas) — corrigido antes de qualquer commit.
6. **Expectativa errada em teste de token:** o token do admin sem `projects.manage` depende do acesso por obra (comportamento correto; teste ajustado).
7. **Processo:** a etapa 4 foi iniciada sem autorização expressa (autorização era até a etapa 3) — registrado e confirmado depois pelo responsável.

## 5. Riscos e pendências

| # | Item | Estado |
|---|---|---|
| P1 | ~~Migrations da Fase 3 no `obraflow`~~ | ✅ Aplicadas com autorização (2026-11-16) |
| P2 | ~~`admin` da HighTech sem as 6 novas permissões~~ | ✅ Concedidas com autorização (2026-11-16), auditado |
| R1 | Dados pessoais de funcionários (LGPD, D-P07): minimização aplicada; política de retenção pendente | Aberto |
| R2 | `document_links` polimórfica (agora com 7 donos): dono garantido pela aplicação + CHECK de tipos | Aceito (D-P12/D-P19) |
| R3 | `user_companies` sem efeito de bloqueio (D-P25) | Decisão futura |
| R4 | Cliente da obra "da mesma empresa" garantido pela aplicação (tabela `customers` homologada sem índice para FK tripla) | Aceito; coberto por testes |
| R5 | Telas (Fase 2 + 3 + login) | Etapa própria; ao concluir, subir os servidores para teste no navegador (pedido do responsável) |
| R6 | Importação das planilhas (`employee`, `equipment`, `product`) | Fora do escopo; exige aprovação |
| R7 | Custos (cargo, salário, encargos, equipamento) | Fase 4 |

**Fase 4: não iniciada** — aguarda aprovação expressa.

## 6. Homologação (2026-11-16)

**Fase 3 homologada pelo responsável** após o teste no navegador, que usou as telas de equipes e obras ligadas ao banco `obraflow`.

| Item | Situação na homologação |
|---|---|
| Banco `obraflow` | 47 migrations. A HighTech tem `admin` com 27 permissões e `member` com 11 (todas de consulta); concessões auditadas |
| Último commit | `d4fb5b5`, telas: CI run 43972095774 verde (API MySQL 8.0 e 8.4, Web) |
| Testes | Backend 364/364 · Frontend 28/28 |

**Pendências que seguem abertas:** LGPD/retenção (R1), `user_companies` sem bloqueio (R3), importação de planilhas (R6) e custos (Fase 4, R7). A etapa das telas ([TELAS_PLANO.md](TELAS_PLANO.md)) tem homologação própria.

**Fase 4 (Orçamentos): não iniciada.** Aguarda autorização expressa.

## 7. Ajustes após a homologação (2026-11-17)

- **Código automático da obra** (pedido do responsável): com o código vazio, o sistema gera **`OB-NNN/AAAA`**.
  - A sequência é por organização e **por ano**, recomeçando em 001 a cada ano.
  - O ano é o da **data de início** da obra; sem data, o ano atual.
  - Códigos de obras excluídas não são reaproveitados.
  - A geração trava as linhas do ano, para evitar duplicidade.
  - Um código digitado à mão é mantido.
  - Nova rota `GET /projects/next-code?start_date=` (`projects.manage`) devolve a sugestão.
- **Defeito corrigido:** buscar com 3 ou mais dígitos em **Obras** ou **Documentos** procurava numa coluna de CPF/CNPJ que essas tabelas não têm, e a resposta era 500. A busca por documento passou a ser opcional e fica desligada nessas telas.
- **Testes:** +2 em `ProjectsApiTest` (código automático e regressão da busca). `RouteAuthorizationTest` agora cobre 47 rotas. Suíte **416/416 (2.827 asserções)**.
