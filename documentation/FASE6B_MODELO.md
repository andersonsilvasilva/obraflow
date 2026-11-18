# FASE 6B — RECURSOS E ORÇAMENTO SINTÉTICO DO PLANEJAMENTO: MODELO APROVADO

> **Aprovado em 2026-11-18** (D-P87 a D-P98 conforme a recomendação — A-57).
>
> **Base:**
> - Fase 6 aprovada (D-P65…D-P76), em especial a D-P72 (custo e venda) e a D-P73 (exportação);
> - **A-50**: três fontes de custo por atividade (recursos do catálogo, composição pública SINAPI e composição própria simplificada) e os botões de cada linha;
> - telas do Brickup ([SCREEN_REFERENCES.md](SCREEN_REFERENCES.md), "Mais detalhes do Planejamento"): aba Recursos, modal "Adicionar item", composição simplificada, orçamento sintético com 28 colunas, BDI e Desconto;
> - o que já existe: cronograma (6A), catálogo de insumos (4C), bases SINAPI (4A), orçamento com BDI e arredondamento (4B, D-P02/D-P32), relatório premium (4D).

## 1. Objetivo

Cada atividade do planejamento passa a ter **custo e venda**, vindos de três fontes:

| Botão na linha | Fonte | O que entra |
|---|---|---|
| **Recursos** | Catálogo da empresa (4C) | Materiais, mão de obra (cargo/função), equipamentos, serviços e outros, com o preço vigente |
| **Comp. pública** | Base SINAPI ativa (4A) | Uma composição, com o custo publicado **separado por tipo** (mão de obra, material, equipamento, serviços, outros) e os insumos para consulta |
| **Comp. própria** | Composições simplificadas da empresa (novo cadastro) | Um valor por tipo; equipamento e mão de obra com três valores (sem encargos, desonerado e não desonerado) |

Com isso, o planejamento mostra o **orçamento sintético**: os cartões de custo do topo (hoje marcados "6B") passam a ter valores; a tabela ganha colunas de custo e venda; há **BDI e desconto**; e a exportação gera a planilha de 28 colunas.

## 2. Tabelas (2 novas + 1 ajuste)

| # | Tabela | Colunas principais | Regras |
|---|---|---|---|
| 1 | `own_compositions` — composição própria simplificada (cadastro da empresa) | `uuid`, `code` (opcional), `name`, `unit_code`, `reference_date`, `state` (UF, opcional), `material_value`, `service_value`, `other_value`, `equipment_value_none`, `equipment_value_relief`, `equipment_value_no_relief`, `labor_value_none`, `labor_value_relief`, `labor_value_no_relief`, `status` | valores ≥ 0 (CHECK); código único entre as ativas do tenant; exclusão lógica (como o catálogo) |
| 2 | `schedule_task_items` — recursos de cada atividade | `task_id`, `source` (`catalog`, `sinapi_resource`, `sinapi_composition`, `own_composition`, `budget`, `manual`), `source_ref` (JSON: cópia da origem), `kind` (`material`, `labor`, `equipment`, `service`, `other` ou `composition`), `code`, `description`, `unit_code`, `quantity_per_unit` (consumo por unidade da atividade), **`unit_cost` e o custo unitário por tipo** (`cost_material`, `cost_labor`, `cost_equipment`, `cost_service`, `cost_other`), `bdi_percent` e `discount_percent` (opcionais: exceção ao do planejamento), `breakdown` (JSON: insumos da composição, só para consulta), `sort_order` | **cópia** do preço no momento (D-08): mudar o catálogo ou a base não altera o planejamento; soma dos tipos = custo unitário (CHECK); só em **atividade** (não em etapa nem marco); mesmo planejamento (FK composta) |
| ± | `schedules` | `bdi_percent`, `discount_percent` | BDI e desconto do planejamento (botões **BDI** e **Desconto**) |

**Valores calculados (não gravados):** a quantidade total do recurso é a quantidade da atividade × o consumo por unidade (atividade sem quantidade conta como 1). Custo e venda seguem a conta do orçamento (D-P72), arredondada **por recurso** (D-P32):

```
custo do recurso = arred₂(quantidade total × custo unitário)
venda do recurso = arred₂(custo × (1 + BDI) × (1 − desconto))
atividade = soma dos recursos · etapa = soma das filhas · por tipo = soma das parcelas de cada tipo
```

O cálculo roda no servidor (classe única, como o `BudgetCalculator`), e a tela só exibe.

## 3. Decisões (aprovadas conforme a recomendação, 2026-11-18)

| ID | Decisão | Recomendação |
|---|---|---|
| **D-P87** | **Onde fica a composição própria** | **Cadastro da empresa**, em Cadastros → Recursos → **Composições próprias**, reutilizável em qualquer obra (como o catálogo). No planejamento, o botão "Comp. própria" escolhe uma existente ou cria uma nova na hora. Retoma a D-P42 no formato simplificado (sem coeficientes) |
| **D-P88** | **Qual valor da composição própria usar** (sem encargos, desonerado ou não desonerado) | O do **regime de encargos da obra** (A-47): obra desonerada usa "desonerado", e assim por diante. Na hora de lançar, o valor fica copiado; se o regime da obra mudar depois, os recursos já lançados não mudam (aviso na tela) |
| **D-P89** | **Composição SINAPI no planejamento** | Entra como **uma linha** com o custo publicado separado por tipo (os 5 tipos do SINAPI) e os insumos guardados só para consulta (painel "ver composição"). Base: a **ativa com o mesmo regime da obra** (D-P03); se houver mais de uma UF, o usuário escolhe. Obra "sem encargos" não usa SINAPI |
| **D-P90** | **Recursos do catálogo** (modal "Adicionar item") | Abas por tipo: **Materiais** (multisseleção), **Mão de obra** (cargo/função do catálogo), **Equipamentos**, **Serviços** e **Outros** (digitado: descrição, unidade e valor). Copia nome, unidade e preço vigente. "Tipos de equipamentos" do Brickup = equipamentos do catálogo |
| **D-P91** | **Consumo do recurso** | O usuário informa o **consumo por unidade da atividade** (Qtd. unitária do Brickup); a quantidade total é calculada. Atividade sem quantidade conta como 1 (o recurso vira o total) |
| **D-P92** | **BDI e desconto** | **Do planejamento** (botões BDI e Desconto), com exceção opcional **por recurso**. Sem BDI por etapa nesta parte (pode vir depois). A fórmula do TCU fica disponível no BDI, como no orçamento |
| **D-P93** | **Planejamento criado do orçamento aprovado** | Ao criar do orçamento, cada atividade **recebe o item do orçamento como recurso** (origem `budget`), com o custo unitário e a divisão por tipo (dos insumos do item). Planejamentos já criados do orçamento ganham o botão "**Trazer custos do orçamento**" (só para atividades sem recursos). O BDI do planejamento começa igual ao global do orçamento |
| **D-P94** | **Importação por planilha** | As linhas de recurso da `planning.xlsx` (Material, Serviços, Equipamento, Mão de obra, Outros), hoje "ignoradas", **passam a entrar** como recursos da atividade logo acima, com valor unitário, quantidade e unidade. A unidade é reconhecida como na 6A |
| **D-P95** | **Telas** | Cartões de custo com valores (Custo, Com BDI, BDI, Desconto, Valor final e venda por tipo); colunas opcionais na tabela (Qtd. unit., Qtd. total, Custo unit., BDI %, Desconto %, Venda unit., Total de custo, Total de venda, Recursos); botões da linha **Recursos**, **Comp. pública** e **Comp. própria**; interruptor **"Visualizar recursos"**, que troca o Gantt por uma tabela em árvore (atividades e os recursos de cada uma), com edição do consumo e exclusão; modelos de colunas **Custo detalhado** e **Venda detalhada** (D-P70) |
| **D-P96** | **Exportação** (D-P73) | **XLSX** com as **28 colunas** do orçamento sintético (custo e venda unitários e totais por tipo) e o cronograma em outra aba, com o mesmo acabamento da 4D. **PDF** do orçamento sintético pelo Chrome (motor da 4D), em paisagem, com capa e "Página X de Y" |
| **D-P97** | **Permissões** | **Sem permissão nova.** Editar recursos = `schedules.manage` + acesso à obra; buscar no catálogo exige `resources.view`, e no SINAPI, `references.view` (como no orçamento). O cadastro de composições próprias usa `resources.view` / `resources.manage` |
| **D-P98** | **Linha de base e custos** | A linha de base continua só com datas (6A). Custos na linha de base (curva S de custo planejado × realizado) ficam para depois, quando houver medição e financeiro |

## 4. Etapas (após aprovação)

1. **Banco e cálculo:** migrations (as 2 tabelas e o ajuste em `schedules`), modelos, o calculador do sintético e testes de valores (conferidos com aritmética decimal independente).
2. **Composições próprias:** cadastro (API e tela em Cadastros → Recursos).
3. **Recursos da atividade:** API para catálogo, SINAPI, composição própria e digitado; BDI e desconto; custos do orçamento (D-P93) e linhas de recurso da planilha (D-P94).
4. **Telas do planejamento:** cartões, colunas, botões da linha, modal "Adicionar item", "Visualizar recursos".
5. **Exportação** XLSX (28 colunas + cronograma) e PDF.
6. **Testes, CI, banco** (migrations no `obraflow` com autorização) e **homologação da 6B**.

## 5. Banco `obraflow` (precisa de autorização)

- **3 migrations:** `own_compositions`, `schedule_task_items` e as colunas `bdi_percent` e `discount_percent` em `schedules`.
- **Sem permissões novas** e **sem dados**: nada é inserido. O planejamento já existente da OB-001/2026 continua igual, só sem recursos até você lançá-los.

A lista exata será apresentada antes de executar, como sempre.

## 6. Riscos

- **Regime da obra × base SINAPI:** obra sem base ativa no mesmo regime não consegue lançar composição pública. A tela avisa e oferece catálogo e composição própria.
- **Desempenho:** planejamentos com centenas de atividades e milhares de recursos. O cálculo é feito numa passada só (como o orçamento); testo com 500 atividades e 3.000 recursos.
- **Composição SINAPI como uma linha:** a divisão por tipo é a publicada pelo SINAPI. Se a soma publicada divergir do total por arredondamento, a diferença vai para "outros" (registrado no recurso).
- **Planejamento × orçamento:** depois de criado, o planejamento é independente (cópia, D-08). Mudanças numa nova versão do orçamento não chegam sozinhas ao planejamento.

## 7. Andamento

### Etapa 1 — banco e cálculo

- **Migrations** `2026_11_18_510001` a `510003` (aplicadas no `obraflow` em 2026-11-18 com autorização — A-59):
  - `own_compositions`: valores ≥ 0, UF válida, código único entre as ativas, exclusão lógica;
  - `schedules`: `bdi_percent`, `bdi_components` (parcelas do TCU) e `discount_percent`, com faixas válidas;
  - `schedule_task_items`: origem e tipo no domínio, **soma dos tipos = custo unitário** (CHECK), percentuais válidos, atividade do mesmo planejamento (FK composta), RESTRICT.
- **Modelos:** `OwnComposition` (custo por tipo conforme o regime da obra, D-P88) e `ScheduleTaskItem`.
- **`ScheduleCostCalculator`:** custo, com BDI e venda por recurso, arredondados por recurso; parcelas por tipo com o centavo de diferença na maior parcela (os tipos somam exatamente o total); atividade, etapa e planejamento; unitários da atividade para as colunas do sintético. Só leitura.
- **Testes:** `Feature/Planning/ScheduleCostTest` (3), com valores conferidos à mão; suíte 472/472.

> Referência recebida durante a etapa: aba **Recursos** do Brickup (árvore de atividades com os recursos, Qtd. unit. editável, "+" por atividade, "Expandir todos", "Exibir apenas atividades", totais de venda e custo) — é a tela "Visualizar recursos" da D-P95 (etapa 4).

### Etapa 2 — composições próprias

- **API** `/own-compositions` (listar com busca e situação, ver, criar, alterar, excluir — exclusão lógica), com `resources.view` / `resources.manage` (D-P97); auditoria `registry.*` (`own_composition`). A resposta traz o **total por regime** (sem encargos, desonerado, não desonerado), calculado.
- **Tela** Cadastros → Recursos → **Composições próprias**: lista com os três totais e formulário com nome, unidade, código, data de referência, UF e os valores por tipo.
- **Testes:** `Feature/Catalog/OwnCompositionTest` (3): totais por regime, validação, código único entre as ativas, permissões e isolamento.

### Etapa 3 — recursos da atividade (API)

- **Rotas** (em `/projects/{uuid}/schedule`, `schedules.manage` + nível `edit` na obra):
  - `PUT pricing`: BDI informado ou pela fórmula do TCU (parcelas guardadas) e desconto (D-P92);
  - `POST tasks/{id}/items`: lança recursos por origem — `catalog` (vários insumos de uma vez, preço vigente), `sinapi_composition` (uma linha com o custo publicado por tipo e os insumos para consulta), `sinapi_resource`, `own_composition` (valores do regime da obra, D-P88) ou `manual`; consumo por unidade (D-P91). Catálogo e composição própria exigem `resources.view`; SINAPI, `references.view` (D-P97);
  - `PUT items/{id}`: consumo e BDI/desconto próprios; descrição e custo só nos digitados (os demais são cópia);
  - `DELETE items/{id}`;
  - `POST items/from-budget`: "Trazer custos do orçamento" (só atividades vinculadas e sem recursos).
- **Regras:** só em atividade (etapa e marco recusam); atividade com recursos não vira etapa; excluir a atividade (ou "Deletar tudo") leva os recursos; SINAPI só com base ativa do **mesmo regime** da obra, e obra "sem encargos" não usa SINAPI (D-P89).
- **Do orçamento (D-P93):** ao criar o planejamento do orçamento aprovado, cada atividade recebe o item como recurso (origem `budget`, custo unitário e divisão por tipo pelos insumos do item; sem insumos = "outros") e o BDI do planejamento começa igual ao global do orçamento. Auditoria `schedule.costs_imported`.
- **Planilha (D-P94):** linhas Material, Serviços, Equipamento, Mão de obra e Outros/Indiretos entram como recursos da atividade acima (ou da indicada pelo item); Valor unit. = custo unitário; Quantidade = consumo por unidade. Recurso em etapa com sub atividades é erro.
- **Resposta do planejamento:** custos de cada atividade/etapa (`cost`), a lista de recursos com os valores calculados (`items`), os totais do sintético (`costs`), BDI, desconto e regime da obra.
- **Testes:** `Feature/Planning/ScheduleItemsTest` (4) e importação atualizada; suíte 479/479.
