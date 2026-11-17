# FASE 4 — REFERÊNCIAS E ORÇAMENTOS: MODELO APROVADO

> **Aprovado em 2026-11-17.** A Fase 4 foi autorizada e as decisões D-P02, D-P03 e D-P29 a D-P36 foram aprovadas conforme a recomendação.
> **Base:**
> - [DATABASE.md](DATABASE.md) §2.6 e §2.7 e [DB_MIGRATION_PLAN.md](DB_MIGRATION_PLAN.md) (Fase 4);
> - D-08 (orçamento aprovado imutável e autossuficiente);
> - padrões das Fases 1 a 3;
> - telas-protótipo de orçamento (referências 2 a 12 em [SCREEN_REFERENCES.md](SCREEN_REFERENCES.md));
> - arquivos SINAPI recebidos (MT, 12/2024, desonerado e não desonerado).

## 1. Escopo e divisão

O plano original reúne 23 tabelas (8 de referências e 15 de orçamento). Proponho dividir a fase em duas partes, cada uma com entrega e homologação próprias:

| Parte | Entrega |
|---|---|
| **4A — Referências técnicas** | Bases oficiais (SINAPI) importadas **somente por comando**, com rastreio (arquivo, hash, competência, localidade, regime). Consulta de insumos, composições e preços pela API. |
| **4B — Orçamentos** | Orçamento por obra/cliente com versões, etapas (EAP), itens, composições instanciadas, insumos consolidados, BDI, custos indiretos, Curva ABC, aprovação e imutabilidade da versão aprovada. Telas no padrão de layout. |

**Fora desta fase:**
- importação de planilhas do cliente (`budget_imports`);
- orçamento inteligente e IA;
- Curva S (depende de cronograma);
- catálogo de materiais por tenant (tela "Cadastro de Produtos" e `product.xlsx`) — ver D-P35;
- contratos e medições.

## 2. Tabelas propostas

### 4A — Referências (globais, somente leitura para os tenants)

| # | Tabela | Colunas principais | Regras |
|---|---|---|---|
| 1 | `reference_sources` | `code` (SINAPI), `name`, `publisher` | `code` único |
| 2 | `reference_datasets` | `source_id`, `version` (ex.: 2024-12), `state`, `charges_regime` (`with_relief`/`without_relief`), `file_name`, `file_sha256`, `imported_at`, `imported_by`, `status` (`importing`/`active`/`superseded`/`failed`), `rows_*` | um ativo por fonte+competência+UF+regime |
| 3 | `reference_units` | `code` (unidade como vem na fonte: M2, UN, H…) | unidades da fonte, separadas das unidades do tenant (D-P14) |
| 4 | `reference_resources` | `dataset_id`, `code`, `description`, `unit_id`, `kind` (material/labor/equipment/service), `family` | `(dataset_id, code)` |
| 5 | `reference_resource_prices` | `resource_id`, `price` DECIMAL(15,2), `price_origin` (coletado/atribuído, como na fonte) | um preço por insumo no dataset |
| 6 | `reference_compositions` | `dataset_id`, `code`, `description`, `unit_id`, `class`, `type`, `group_code`, `total_cost` | `(dataset_id, code)` |
| 7 | `reference_composition_items` | `composition_id`, `resource_id` NULL / `child_composition_id` NULL, `coefficient` DECIMAL(18,7) | exatamente um dos dois (CHECK) |

As tabelas `reference_competencies` e `reference_locations` do plano original viram colunas do dataset. Cada arquivo SINAPI já vem com uma competência, uma localidade e um regime (D-P29).

### 4B — Orçamentos (por tenant, FK composta, auditados)

| # | Tabela | Colunas principais |
|---|---|---|
| 8 | `budgets` (SD) | `uuid`, `company_id`, `project_id` NULL, `customer_id` NULL, `code`, `title`, `mode` (customer/manual/reference), `reference_dataset_id` NULL, `state`, `social_charges_regime`, `reference_date`, `status`, `current_version_id`, `owner_id`, `notes` |
| 9 | `budget_versions` | `budget_id`, `version_number`, `label`, `status`, `created_by`, `approved_at`, `locked_at`, `totals_snapshot` json |
| 10 | `budget_groups` | `budget_version_id`, `parent_id` (mesma versão), `code` (1, 1.1, 1.1.1), `name`, `sort_order` |
| 11 | `budget_items` | `budget_version_id`, `group_id` (mesma versão), `code`, `description`, `unit_code`, `quantity` (4 casas), `unit_cost` (2), `bdi_percent`, `discount_percent`, `total_cost`, `total_sale`, `source` (official/custom/manual), `source_ref` (cópia, **sem FK** — D-08) |
| 12 | `budget_compositions` | composição **copiada** para o item: `budget_item_id`, `code`, `description`, `unit_code`, `unit_cost`, `source_snapshot` json (fonte, versão, UF, regime) |
| 13 | `budget_composition_items` | `budget_composition_id`, `kind`, `code`, `description`, `unit_code`, `coefficient` (7 casas), `unit_price`, `cost` |
| 14 | `budget_resources` | insumos consolidados da versão: `kind`, `code`, `description`, `unit_code`, `quantity`, `unit_price`, `total` |
| 15 | `budget_bdi` | `budget_version_id`, `scope` (global/group/item), `group_id`/`item_id` NULL (FK composta), `components` json, `percent` |
| 16 | `budget_indirect_costs` | `budget_version_id`, `description`, `method` (fixed/percent_of_direct), `percent`, `amount` |
| 17 | `budget_approvals` (append-only) | `budget_version_id`, `decision`, `decided_by`, `decided_at`, `comment`, `totals_hash` |
| 18 | `budget_status_history` (append-only) | `budget_id`, `budget_version_id`, `from_status`, `to_status`, `changed_by`, `reason`, `changed_at` |

`budget_costs`, o cache de totais do plano original, é substituído por `totals_snapshot` na versão: o recálculo é feito pelo servidor e o cache fica dentro da própria versão (D-P33).

## 3. Decisões (aprovadas conforme a recomendação, 2026-11-17)

| ID | Decisão | Opções | Recomendação |
|---|---|---|---|
| **D-P02** | **BDI e custos indiretos** (fórmula e incidência do desconto) | (A) **percentual informado** pelo usuário (global, por etapa ou por item); (B) **fórmula do TCU** (Acórdão 2622/2013): BDI = [(1+AC+S+R+G)·(1+DF)·(1+L) / (1−I)] − 1, com os componentes guardados; (C) as duas | **(C)**: o padrão é o percentual informado; a fórmula do TCU é opcional e a calculadora guarda os componentes. **Desconto** sobre o **preço de venda** (depois do BDI), por item e global, nunca reduzindo o custo. **Custos indiretos** como linhas próprias (valor fixo ou % do custo direto), fora do BDI |
| **D-P03** | **Encargos sociais** | `with_relief` / `without_relief` / `not_applicable` | **Manter os três.** O orçamento herda o regime da base escolhida (o SINAPI recebido vem desonerado e não desonerado); **sem cálculo próprio** de encargos; o regime fica gravado no orçamento e na versão |
| **D-P29** | **Referências globais × isolamento** | (A) tabelas globais sem `tenant_id`, só leitura para tenants e escritas apenas por comando de plataforma; (B) cópia por tenant | **(A)**: são dados públicos e iguais para todos; copiar por tenant multiplica milhões de linhas. Ficam fora da regra de FK composta, como `permissions`, e documentadas como exceção. O orçamento **copia** o que usa (D-08), sem FK para as referências |
| **D-P30** | **Importação SINAPI** | comando artisan × tela | **Comando** `obraflow:references:import <arquivo> --source=SINAPI --state=MT --regime=…`: idempotente pelo hash, transacional por lote e auditado. Testes com um **recorte pequeno** dos arquivos. Importar no banco `obraflow` exige **sua autorização** (são dados oficiais reais, não fictícios) |
| **D-P31** | **Imutabilidade da versão aprovada (D-08)** | trigger × aplicação | **Aplicação** (sem trigger: a regra do projeto proíbe triggers). Versão com `locked_at` recusa qualquer escrita (serviço + modelo, testado). Editar orçamento aprovado = **nova versão** copiada da anterior |
| **D-P32** | **Precisão e cálculo** | — | `quantity` DECIMAL(15,4), `coefficient` DECIMAL(18,7), preços e totais DECIMAL(15,2). Arredondamento **por item** (meio para cima) e soma dos itens arredondados. Todo cálculo no servidor, com aritmética decimal e testes com valores conferidos; o frontend só exibe |
| **D-P33** | **Totais e Curva ABC** | tabela de cache × calculado | Recálculo no servidor a cada alteração, gravado em `totals_snapshot` da versão; Curva ABC **calculada** sob demanda (sem tabela); faixas A/B/C em 80/15/5 %, configuráveis depois |
| **D-P34** | **Fluxo e permissões** | — | Status: `draft` → `in_review` → `approved` / `rejected`; rejeitado volta a `draft`. Permissões: `budgets.view`, `budgets.manage`, `budgets.approve` (quem aprova não precisa editar) e `references.view`. Orçamento ligado a obra respeita o **acesso por obra** (D-P24). Aprovação grava `totals_hash` (SHA-256 dos totais) |
| **D-P35** | **Catálogo de materiais do tenant** (tela "Cadastro de Produtos", `product.xlsx`) | nesta fase × depois | **Depois** (fase de suprimentos ou 4C): na 4B, itens próprios entram como `custom`/`manual` no orçamento, sem catálogo |
| **D-P36** | **Telas** | junto × depois | **Junto com a 4B**, no padrão de layout. Os protótipos atuais de orçamento (marcados "Demo") serão substituídos pelas telas reais |

## 4. Etapas (após aprovação)

**4A**
1. Referências: migrations, modelos e importador SINAPI (recorte de teste).
2. API de consulta: busca de insumos e composições por base ativa.
3. Testes, CI e homologação 4A. A importação real no `obraflow` só ocorre com autorização.

**4B**
4. Orçamentos, versões, etapas e itens; cálculo e imutabilidade.
5. Composições copiadas da referência, insumos consolidados, BDI, custos indiretos e Curva ABC.
6. Aprovação, histórico, permissões e auditoria; API.
7. Telas, testes, CI e homologação 4B.

## 5. Riscos

- **Volume:** a base analítica do SINAPI tem centenas de milhares de linhas por arquivo. A importação será em lotes, e as consultas terão índices e busca paginada.
- **Fidelidade:** os valores vêm da fonte oficial e **não são editados** na base. Ajustes de preço ficam no orçamento, como `custom`.
- **Fórmula de BDI:** é regra contábil, e a fórmula do TCU é referência de obra pública. Cabe ao responsável validar o uso em obra privada (D-P02).
- **Licença e uso:** o SINAPI é publicação pública (Caixa/IBGE). A fonte, a competência e a localidade ficam registradas em cada item copiado.

## 6. Andamento

### 4A.1 — Referências: tabelas, importador e testes

**8 tabelas globais** (migrations `2026_11_17_100001` a `100008`), sem `tenant_id`:
`reference_sources`, `reference_datasets`, `reference_imports`, `reference_units`, `reference_resources`, `reference_resource_prices`, `reference_compositions`, `reference_composition_items`.

**Ajustes de implementação** (todos dentro do modelo aprovado):
- **`reference_imports`:** tabela nova, com um registro por arquivo (insumos, composições, famílias) e a base que recebe esses arquivos. Guarda nome, SHA-256 e contagens.
- **Itens de composição:** guardam também o preço unitário, o custo e a origem do preço publicados na linha.
- **Composições:** guardam a decomposição publicada (mão de obra, material, equipamento, serviços, outros).
- **Tipo do insumo:** vem da **Macro-Classe** do relatório de famílias da fonte:
  - MATERIAL → material; MÃO DE OBRA → labor; EQUIPAMENTO (AQUISIÇÃO/LOCAÇÃO) → equipment; SERVIÇOS → service;
  - ESPECIAIS fica sem tipo.
- **Integridade:** `dataset_id` vai nos itens, com FKs `(dataset_id, x_id)`. O banco recusa item apontando para insumo ou composição de **outra base**. O CHECK exige exatamente um alvo.

**Comando** `obraflow:references:import --competency=AAAA-MM --state=UF --regime=with_relief|without_relief --resources=… --compositions=… --families=… [--activate] [--dry-run]`:
- leitor XLSX próprio por streaming (sem dependência nova);
- confere o cabeçalho de cada arquivo (competência e regime) contra a base;
- idempotente pelo hash; recusa outro arquivo para um tipo já importado;
- tudo numa transação; composição filha ausente aborta tudo;
- auditoria `reference.imported` e `reference.activated`, com `tenant_id` nulo;
- `--activate` marca como `superseded` a base ativa anterior da mesma fonte, UF e regime.

**Validação com os arquivos reais** (SINAPI MT 12/2024 desonerado, **só no `obraflow_testing`**):
- 4.839 insumos, 7.829 composições e 40.538 itens, em cerca de 1 minuto;
- valores conferidos com a planilha: composição 97141 com total 3,84 (mão de obra 1,49, material 0,93, equipamento 1,42); item 5678 com coeficiente 0,0063000, preço 139,00 e custo 0,87; insumo 11270 a 2,66 (CR);
- a reexecução não duplica nada;
- arquivo com outro regime ou outra competência é recusado.

**Testes:** `Feature/References/ReferenceImportTest` (9), sobre um recorte com o mesmo layout montado em XLSX na hora (`Tests\Support\XlsxFixture`). Suíte **373/373 (2.341 asserções)**.

**Banco `obraflow`:** sem alterações. A importação real exige autorização (D-P30).
Commit `4961b76`.

### 4A.2 — API de consulta

- **Rotas:** 5 rotas `GET /references/...`, todas em `tenant.required` com `references.view`. Detalhe em [API.md](API.md) §Fase 4A.
- **Busca:** por código (prefixo) ou palavras da descrição, com curingas escapados.
- **Visibilidade:** só bases ativas ou substituídas.
- **Permissão:** `references.view` entra no catálogo (28 permissões). O `member` de **tenants novos** recebe a permissão. Na HighTech, a permissão só é instalada e concedida com autorização.
- **Testes:** `Feature/References/ReferenceApiTest` (8): listagem, busca, detalhe com itens, 404 de base indisponível, base substituída, permissão e tenant, ausência de escrita, token. O recorte foi para `Tests\Support\SinapiFixture`. Suíte **381/381 (2.424 asserções)**.

Commit `bb224f4`. CI verde nos dois commits da 4A: run 44092650459 (`4961b76`) e run 44093330126 (`bb224f4`), em MySQL 8.0, MySQL 8.4 e Web.

### 4A.3 — Banco `obraflow` (autorizado, 2026-11-17)

| Passo | Resultado |
|---|---|
| Permissão `references.view` | Instalada; catálogo com **28** permissões |
| Papéis da HighTech | `admin` com 28 e `member` com 12 permissões (todas de consulta). Dois eventos `permission.granted`; a segunda execução respondeu "nada a fazer" |
| Migrations da 4A | 8 aplicadas; **55** no total |
| SINAPI MT 12/2024 **desonerado** (`with_relief`) | Base 1 ativa: 4.839 insumos, 7.829 composições, 40.538 itens. Composição 97141: total 3,84, mão de obra 1,49 |
| SINAPI MT 12/2024 **não desonerado** (`without_relief`) | Base 2 ativa: mesmas contagens. Composição 97141: total 4,07, mão de obra 1,74 (diferença dos encargos) |
| Rastreio | 6 arquivos com SHA-256 em `reference_imports`; 8 eventos `reference.*`. Total de 27 registros de auditoria |

- **Tipos de insumo:** 3 insumos por base ficam sem tipo (Macro-Classe "ESPECIAIS", como publicada).
- **Dados dos tenants:** nenhum cadastro de tenant foi criado.

**Parte 4A homologada pelo responsável em 2026-11-17.** A parte 4B (Orçamentos) foi autorizada.

### 4B.4 — Orçamentos, versões, etapas, itens, BDI, custos indiretos, cálculo e imutabilidade

**6 tabelas por tenant** (migrations `2026_11_17_200001` a `200006`):
`budgets`, `budget_versions`, `budget_groups`, `budget_items`, `budget_bdi`, `budget_indirect_costs`.
- **Por que BDI e indiretos entraram agora:** foram antecipados da 4B.5, porque o cálculo depende deles.
- **Isolamento e versão:** FK composta com `tenant_id` e, nos filhos, com a **versão**. O banco recusa etapa-pai, etapa do item, BDI de etapa ou de item que sejam de outra versão. A versão corrente precisa ser do próprio orçamento.
- **CHECKs:**
  - modo "referência" exige base;
  - versão aprovada exige `locked_at`;
  - percentuais e valores no domínio;
  - BDI coerente com o escopo, e o método TCU exige componentes;
  - custo indireto fixo **ou** percentual, nunca os dois.
- **Base de referência:** o orçamento referencia a base global com FK simples. O teste estrutural de isolamento passou a isentar FKs para tabelas **globais** (sem `tenant_id`).

**Cálculo** (`BudgetCalculator`, `bcmath`; D-P02, D-P32, D-P33):
- **Item:** custo = arred₂(quantidade × custo unitário); venda = arred₂(arred₂(custo × (1+BDI)) × (1 − desconto do item)).
- **BDI efetivo:** o do item, senão a etapa mais próxima subindo na árvore, senão o global, senão zero.
- **Versão:**
  - indiretos (fixo ou % do custo direto, fora do BDI);
  - desconto global sobre (vendas + indiretos);
  - snapshot com custo direto, indireto, total, BDI, descontos, preço final e subtotais por etapa (com as subetapas).
- **Fórmula do TCU** (`BdiFormula`): opcional; guarda os componentes.

**Serviço** (`BudgetService`): toda mudança roda em transação, com auditoria (`registry.*`) e recálculo. Modo "referência" herda a UF e o regime da base ativa. Nova versão:
- só a partir de uma versão aprovada ou rejeitada;
- copia etapas (árvore remapeada), itens, BDI e indiretos;
- vira a versão corrente, em rascunho.

**Imutabilidade (D-P31, sem trigger):**
- Versão bloqueada recusa criar, alterar ou excluir etapas, itens, BDI, indiretos e desconto, além de recalcular e de ser excluída (422 `domain_rule`).
- A única mudança aceita é de aprovada para substituída.

**Testes:**
- `Feature/Budgets/BudgetCalculationTest` (13): valores conferidos com cálculo decimal independente. Exemplo: custo direto 455,15, indiretos 522,76, preço final 1.037,08; TCU 25,88%.
- `Feature/Database/BudgetIntegrityTest` (6).
- Suíte **399/399 (2.548 asserções)**.
Commit `011041f`.

### 4B.5 — Itens a partir das referências, insumos finais e Curva ABC

**3 tabelas** (migrations `2026_11_17_200007` a `200009`):
- `budget_compositions`: composição copiada, uma por item, com `source_snapshot` (fonte, base, competência, UF, regime, classe, origem do preço);
- `budget_composition_items`: itens diretos, insumos e composições auxiliares, como publicados;
- `budget_item_resources`: **insumos finais** do item, com a árvore expandida e o coeficiente acumulado **por unidade do item**.
  - Substitui `budget_resources` e `budget_resource_items` do plano: a consolidação é calculada sob demanda (D-P33).
- Todas com FK composta pela **versão**.

**`addReferenceItem` (D-08):**
- **Cópia:** copia a composição (custo total **publicado**) ou o insumo (preço publicado). Insumo sem preço na base é recusado.
- **Regime e UF:** a base precisa ter o mesmo regime de encargos da versão (D-P03) e a mesma UF do orçamento.
- **Base substituída:** continua utilizável.
- **Item auxiliar sem preço:** na expansão, um insumo sem preço na base usa o preço publicado na linha da composição.

**Ajustes e versões:**
- Alterar o preço de um item oficial muda a origem para `custom`; o valor publicado fica no `source_ref` (D-P02).
- Excluir o item remove a composição e os insumos.
- A nova versão copia composições e insumos.

**Curva ABC** (`AbcCurve`, sem tabela):
- **Serviços:** por custo total do item.
- **Insumos:** quantidade = Σ quantidade do item × coeficiente; valor = arred₂(quantidade × preço).
- **Saída:** % e % acumulado com 4 casas.
- **Faixas:** A se o item **começa** antes de 80%; B antes de 95%; C o restante.

**Testes:** `Feature/Budgets/BudgetReferenceTest` (8): cópia, árvore expandida, ABC com valores, regime/UF, preço `custom`, exclusão, nova versão, FKs por versão. Suíte **407/407 (2.620 asserções)**.
Commit `8c6b862`. CI verde: run 44099384177 (`011041f`) e run 44101062816 (`8c6b862`).

### 4B.6 — Fluxo de aprovação, permissões, auditoria e API

**2 tabelas append-only** (migrations `200010` e `200011`):
- `budget_approvals`: decisão, autor membro do tenant, comentário e **`totals_hash`** (SHA-256 canônico dos totais);
- `budget_status_history`: a versão precisa ser do **próprio** orçamento (FK composta).

**`BudgetWorkflow` (D-P34):**
- **Transições:** rascunho → em revisão → aprovado | rejeitado. Em revisão pode **voltar** a rascunho; rejeitado pode ser **reaberto**.
- **Aprovar:** recalcula, bloqueia a versão, grava a decisão com o hash e marca a aprovada anterior como `superseded`.
- **Rejeitar:** exige motivo.
- **Enviar:** exige ao menos um item.
- **Registro:** histórico e auditoria na mesma transação, com as linhas travadas.
- **Edição:** só em **rascunho**; o serviço confere.

**`BudgetAccess`:** combina a permissão do módulo com o **acesso por obra** quando o orçamento tem obra. A listagem mostra orçamentos sem obra e os das obras visíveis.

**Permissões:** `budgets.view`, `budgets.manage` e `budgets.approve` entram no catálogo (31 permissões). Aprovar não exige editar. O `member` de tenants novos recebe `budgets.view`.

**Auditoria:**
- eventos `budget.submitted`, `withdrawn`, `approved`, `rejected`, `reopened`, `version_created`;
- o CRUD continua em `registry.*`;
- cobertura `AuditEvent::phase4()` testada.

**API:** 23 rotas em `routes/api/v1/budgets.php`. Detalhe em [API.md](API.md) §Fase 4B.

**Testes:** `Feature/Budgets/BudgetApiTest` (7): totais pela API (preço final 219,74 com BDI do TCU, indiretos e desconto), validação, fluxo completo com nova versão e substituição, aprovador sem edição, acesso por obra, isolamento e token, eventos. Suíte **414/414 (2.795 asserções)**.

**Banco `obraflow`:** sem alterações. As migrations da 4B (11 tabelas) e as permissões de orçamento serão pedidas no fechamento.
Commit `8387d64`.

### 4B.7 — Telas de orçamento (padrão de layout)

- **Lista** (`/orcamentos`), na base de listagem L1–L6: busca, filtros por status e empresa, preço final da versão atual.
  - **Criação** em modal: empresa, código, título, tipo (base de referência, manual ou do cliente), base SINAPI (UF e regime vêm dela), obra, cliente.
  - Ao criar, o orçamento abre direto.
- **Detalhe** (`/orcamentos/:uuid`):
  - **Cabeçalho:** status, regime, base, seletor de versão; ações do fluxo conforme o status e as `abilities` (Enviar, Devolver, Aprovar com confirmação sobre o bloqueio, Rejeitar com motivo, Reabrir, Nova versão).
  - **Indicadores:** custo direto, BDI, indiretos, descontos e preço final.
  - **Planilha:** EAP com subtotais e itens, com origem (oficial/ajustado, fonte, código, UF, competência); etapa, item manual, edição e exclusão; ícone de pasta para a composição copiada e os insumos finais.
  - **Da base de referência:** busca composições ou insumos nas bases compatíveis com o regime, com preço publicado; escolha da etapa e da quantidade.
  - **BDI e indiretos:** BDI global informado ou pela fórmula do TCU (componentes), BDIs por etapa ou item, custos indiretos (valor ou % do direto), desconto global.
  - **Curva ABC:** serviços e insumos, com faixas A, B e C.
  - **Histórico:** status e decisões, com o `totals_hash`.
- **Edição:** só com a versão atual em rascunho e não bloqueada. Versões antigas ficam somente leitura, com aviso.
- **Valores:** todos vêm da API como texto exato. O navegador só formata, não recalcula (D-P32).
- **Protótipos substituídos:**
  - **Removidos:** `BudgetCreatePage`, `BudgetEditorPage`, `BudgetFinalizationPage`, `BudgetWorkspace`, `CompositionPanel` e o cálculo local `calc.ts` (com seus 7 testes).
  - **Mantido:** o protótipo "Orçamento inteligente (IA)", como **demo** em "Em preparação" (IA fora desta fase).
- **Testes frontend:** **25/25**, com o novo `budgets.test.ts` (ações do fluxo por status/permissão, árvore de etapas). `tsc`, lint e build ok.
Commit `c0f5568`. CI verde: run 44103187515 (`8387d64`) e run 44104212735 (`c0f5568`).

### 4B — Banco `obraflow` (autorizado, 2026-11-17)

| Passo | Resultado |
|---|---|
| Migrations da 4B | 11 aplicadas; **66** no total; tabelas vazias |
| Permissões | `budgets.view`, `budgets.manage` e `budgets.approve` instaladas; catálogo com **31** permissões |
| Papéis da HighTech | `admin` com 31 permissões; `member` com 13 (+ `budgets.view`, só consulta). Concessões auditadas; a segunda execução respondeu "nada a fazer" |

Nenhum orçamento foi criado. Os servidores foram ligados para teste no navegador.

**Parte 4B aguarda homologação.**
