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