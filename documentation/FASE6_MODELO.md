# FASE 6 — PLANEJAMENTO E DIÁRIO DE OBRA: MODELO APROVADO

> **Aprovado em 2026-11-17** (D-P65 a D-P76 conforme a recomendação). A parte 6A (Cronograma) está autorizada; 6B e 6C seguem após a homologação da anterior.
> **Base:**
> - modelagem original ([DATABASE.md](DATABASE.md) §2.8 e §2.9);
> - telas do Brickup enviadas pelo responsável ([SCREEN_REFERENCES.md](SCREEN_REFERENCES.md), "Referências do Brickup": cronograma, recursos, orçamento sintético, importação, visualização, RDO);
> - o que já existe: calendário e horários da obra ([CALENDARIO_OBRA_MODELO.md](CALENDARIO_OBRA_MODELO.md)), catálogo de insumos (4C), orçamento aprovado e imutável (4B), equipe e equipamentos (Fase 3), documentos (Fase 2).

## 1. Objetivo

Dentro da obra, o menu **Planejamento** passa a ter:
- **cronograma** (Gantt) com etapas e atividades em níveis, prazos em dias úteis, dependências, caminho crítico e linhas de base;
- **recursos de cada atividade**, com custo e venda: o "orçamento sintético" do planejamento;
- **RDO** (Relatório Diário de Obra): o que aconteceu em cada dia. Ele alimenta o avanço do cronograma e os indicadores "em breve" da obra (atividades atrasadas, RDOs criados).

## 2. Divisão em três partes (cada uma com homologação própria)

| Parte | Entrega | Depende de |
|---|---|---|
| **6A — Cronograma** | Planejamento da obra, atividades em níveis, datas e duração em dias úteis, dependências, caminho crítico, linhas de base, Gantt, criação a partir do orçamento aprovado / planilha / em branco | calendário e horários (prontos) |
| **6B — Recursos e orçamento sintético** | Recursos por atividade (catálogo e SINAPI), custo, BDI, desconto e venda; totais por tipo; exportação do orçamento sintético | 6A |
| **6C — RDO (Diário de Obra)** | Um RDO por dia: clima, mão de obra, equipamentos, horas produtivas, atividades executadas e paralisadas, materiais, ocorrências, visitas e fotos; aprovação; avanço do cronograma | 6A (6B opcional) |

## 3. Tabelas propostas

### 3.1 Parte 6A — Cronograma (5 tabelas)

| # | Tabela | Colunas principais | Regras |
|---|---|---|---|
| 1 | `schedules` | `project_id` (**um por obra**), `origin` (`budget`, `spreadsheet`, `blank`), `budget_version_id` (opcional), `bdi_percent` e `discount_percent` (6B), `created_by` | um planejamento ativo por obra (UNIQUE) |
| 2 | `schedule_tasks` | `schedule_id`, `parent_id`, `wbs_code` (1, 1.1, 1.1.1… recalculado), `name`, `kind` (`summary` = etapa, `task` = atividade, `milestone` = marco), `unit_code`, `quantity`, `planned_start`, `planned_end`, `duration_days` (dias úteis), `actual_start`, `actual_end`, `percent_complete`, `budget_item_id` (opcional), `sort_order` | etapa = soma das filhas (datas e %); marco tem duração 0; até 6 níveis |
| 3 | `schedule_dependencies` | `predecessor_id`, `successor_id`, `type` (`FS` término→início, `SS`, `FF`, `SF`), `lag_days` | mesmo planejamento (FK composta); sem ciclo (aplicação); sem auto-dependência (CHECK) |
| 4 | `schedule_baselines` | `schedule_id`, `number` (1…10), `name`, `snapshot` (JSON com as datas e durações congeladas), `created_by`, `created_at` | só inserção; no máximo 10 por planejamento (como o Brickup, "0/10") |
| 5 | `schedule_progress_history` | `task_id`, `percent_complete`, `source` (`manual`, `diary`), `diary_id`, `recorded_by`, `recorded_at` | só inserção: o histórico do avanço |

### 3.2 Parte 6B — Recursos (1 tabela + 2 colunas)

| # | Tabela | Colunas principais | Regras |
|---|---|---|---|
| 6 | `schedule_task_resources` | `task_id`, `kind` (`material`, `labor`, `equipment`, `service`, `other`), `resource_id` (catálogo 4C, opcional), `source_ref` (cópia da origem: catálogo, SINAPI ou manual), `description`, `unit_code`, `quantity_per_unit` (consumo por unidade da atividade), `unit_cost`, `bdi_percent` e `discount_percent` (opcionais; herdam do planejamento) | **cópia** do preço no momento (D-08), como no orçamento; recurso só em atividade (não em etapa) |
| ± | `schedules` | `bdi_percent`, `discount_percent` | BDI e desconto do planejamento (botões "BDI" e "Desconto" do Brickup) |

### 3.3 Parte 6C — RDO (7 tabelas + 1 ajuste)

| # | Tabela | Colunas principais | Regras |
|---|---|---|---|
| 7 | `construction_diaries` | `uuid`, `project_id`, `number` (1, 2, 3… por obra), `diary_date`, `weather_morning` / `weather_afternoon` / `weather_night` (`clear`, `cloudy`, `rain`, `none`), `workable_morning/afternoon/night`, `productive_hours` (JSON: períodos do dia, pré-preenchidos com o horário da obra), `status` (`draft`, `submitted`, `approved`), `author_id`, `approved_by`, `approved_at`, `notes` | **um por obra e dia** (UNIQUE); aprovado é imutável |
| 8 | `diary_labor` | `diary_id`, `employee_id` **ou** `job_title_id` + `headcount`, `hours` | funcionário da equipe da obra ou cargo com quantidade |
| 9 | `diary_equipment` | `diary_id`, `equipment_id` (Fase 3) ou descrição, `hours_used` | |
| 10 | `diary_activities` | `diary_id`, `task_id` (atividade do cronograma), `status` (`executed`, `paralyzed`), `quantity` **ou** `percent`, `reason` (se paralisada) | executada soma ao avanço da atividade quando o RDO é aprovado |
| 11 | `diary_materials` | `diary_id`, `movement` (`received`, `used`), `resource_id` (catálogo) ou descrição, `unit_code`, `quantity`, `supplier_id` (recebido, opcional) | estoque e notas fiscais ficam para a Fase 7 |
| 12 | `diary_occurrences` | `diary_id`, `kind` (`occurrence`, `comment`), `description`, `impact` (opcional) | ocorrências e comentários gerais |
| 13 | `diary_visits` | `diary_id`, `visitor_name`, `organization`, `purpose`, `arrived_at`, `left_at` | ⚠ nome de visitante é dado pessoal (LGPD, D-P07): só o necessário |
| ± | `document_links` | aceita `construction_diary` | **fotos** do RDO pelos documentos (disco privado, D-P55) |

**Muda em relação à modelagem original:** `schedule_milestones` vira tarefa do tipo `milestone`; `construction_diary_weather` vira colunas do próprio RDO (são sempre 3 períodos); `construction_diary_teams` passa a apontar funcionário ou cargo; `construction_diary_attachments` dá lugar a `document_links`; entram `diary_materials` e `diary_visits` (telas do Brickup).

**Total:** 13 tabelas novas e 1 ajuste. As tabelas do RDO e do cronograma não têm exclusão lógica: o rascunho pode ser excluído; o aprovado, nunca.

## 4. Decisões para aprovação

| ID | Decisão | Recomendação |
|---|---|---|
| **D-P65** | Dividir em **6A, 6B e 6C** com homologações próprias | **Sim**, nesta ordem. O RDO (6C) não depende da 6B |
| **D-P66** | **Duração da atividade**: dias corridos (como o Brickup mostra) ou **dias úteis** pelo calendário da obra? | **Dias úteis**: usa os horários (dias sem horário não contam) e as exceções (feriados, paradas) que já cadastramos. Se a obra não tem horário nem exceções, todo dia é útil e o resultado é igual a dias corridos. A tela mostra os dois |
| **D-P67** | **Reprogramação automática** pelas dependências | **Sim**: ao mudar uma atividade, as sucessoras se movem (término→início + atraso, e os outros 3 tipos); a etapa cobre as filhas; ciclo é recusado. O **caminho crítico** (folga zero) é calculado no servidor (método CPM) |
| **D-P68** | **Como criar o planejamento** (as 4 opções do Brickup) | (a) **Do orçamento aprovado da obra**: etapas → etapas, itens → atividades com quantidade e unidade, mantendo o vínculo; (b) **importar planilha** no nosso modelo (baseado no `planning.xlsx`, com pré-visualização como no catálogo); (c) **em branco**. (d) **Com IA** fica para a Fase 9, e "modelo pronto" (biblioteca de modelos da empresa) fica para depois |
| **D-P69** | **Componente de Gantt** | Biblioteca de licença **livre (MIT)** compatível com React 19. Avalio **SVAR React Gantt** (MIT) no início da 6A: barras arrastáveis, dependências, zoom, marcos, "Hoje". Se não servir (licença, tamanho ou acessibilidade), desenho o Gantt próprio, só com SVG. Sem bibliotecas pagas (dhtmlx PRO, Bryntum). Escolha registrada antes de instalar |
| **D-P70** | **Visualização** (configuração do Brickup) | Modos **Somente tabela**, **Layout dividido** e **Somente Gantt**; colunas visíveis escolhidas por usuário; modelos "Padrão", "Cronograma", "Custo detalhado" e "Venda detalhada". Preferência **por usuário e obra**, guardada no navegador (não é dado de negócio) |
| **D-P71** | **Linha de base** | **Até 10 por planejamento**, congeladas (só inserção). "Comparar com" mostra a linha de base ao lado do planejamento atual, com o desvio em dias |
| **D-P72** | **Custo e venda dos recursos** (6B) | Recurso = **cópia** do catálogo (4C), da base SINAPI ou digitado (D-08). Venda = custo × (1 + BDI) × (1 − desconto), arredondada por recurso: **a mesma conta do orçamento** (D-P02/D-P32). BDI e desconto do planejamento, com exceção por recurso. Totais por tipo (material, mão de obra, equipamento, serviço, outros) = o **orçamento sintético** |
| **D-P73** | **Exportar** (orçamento sintético e cronograma) | **Planilha .xlsx** com as 28 colunas do orçamento sintético do Brickup e o cronograma em outra aba. Biblioteca livre para escrever xlsx (OpenSpout, MIT) no backend, a confirmar no início da 6B |
| **D-P74** | **RDO: um por dia, numerado, com aprovação** | Um por obra e data (Nº 1, 2, 3…). Rascunho → enviado → aprovado; aprovado é imutável. Permissão própria de aprovar. **Sem separação de funções** (diferente da medição: o RDO não gera valor a pagar), mas quem aprovou fica registrado |
| **D-P75** | **RDO → avanço do cronograma** | Ao **aprovar** o RDO, as atividades executadas somam ao avanço (quantidade ou %, a escolha "Quantidade/Porcentagem" do Brickup), com histórico (`diary`). O avanço manual continua possível (`manual`). "Atividades atrasadas" = término planejado já passou e menos de 100% |
| **D-P76** | **Horas produtivas e clima** | As horas produtivas do dia nascem do **horário da obra** para aquele dia da semana (aba Horários), editáveis no RDO. Clima por período (manhã, tarde, noite) com "dia trabalhável" sim/não. **Exportar RDO** em PDF fica para depois; nesta fase, página própria para impressão |

## 5. Permissões (5 novas → 40)

| Permissão | Uso | `member` de tenants novos |
|---|---|---|
| `schedules.view` / `schedules.manage` | ver / editar o planejamento (6A e 6B) | `schedules.view` |
| `diary.view` / `diary.manage` / `diary.approve` | ver / lançar e enviar / aprovar o RDO | `diary.view` |

Tudo segue o **acesso por obra** (D-P24): ver exige ver a obra; editar exige nível `edit`.

## 6. Telas (obra → menu superior, como no Brickup)

A obra ganha a navegação **Geral · RDO · Planejamento · Contratos · Calendário · Horários…**. As abas atuais continuam. A organização final das abas é decidida na tela, com o padrão já homologado.

- **Planejamento (6A/6B):**
  - totais no topo (6B): Custo, Com BDI, BDI, Desconto, Valor final e venda por tipo;
  - barra com Caminho crítico, Linha de base, BDI, Desconto, Importar, Exportar, Exceções (calendário), configurações e zoom;
  - tabela editável (ID, Atividade, Início, Término, Duração, Predecessoras…) e Gantt (diário, semanal ou mensal), com "Hoje";
  - recursos de cada atividade num painel ao lado ou abaixo, com o modal "Adicionar item" por tipo.
- **RDO (6C):**
  - navegação por data (‹ 12 de novembro de 2026 ›), Nº do RDO, aprovação;
  - blocos Clima, Mão de obra, Equipamento, Horas produtivas, Atividades (Quantidade/Porcentagem), Atividades paralisadas, Materiais recebidos e utilizados, Ocorrências, Comentários, Visitas e Fotos, cada um com o botão "Entrada";
  - página de impressão.

## 7. Etapas (após aprovação)

**6A — Cronograma**
1. Tabelas, cálculo de dias úteis (calendário + horários), reprogramação, caminho crítico, linhas de base e API.
2. Criação a partir do orçamento aprovado, da planilha (com pré-visualização) e em branco.
3. Telas: tabela e Gantt, visualização, linha de base. Testes, CI, banco (com autorização) e **homologação da 6A**.

**6B — Recursos e orçamento sintético**

4. Recursos por atividade (catálogo, SINAPI e digitado), BDI/desconto, totais por tipo e exportação xlsx. Telas. **Homologação da 6B**.

**6C — RDO**

5. Tabelas, regras (um por dia, aprovação, avanço do cronograma) e API.
6. Telas (o RDO do dia, a lista de RDOs, fotos, impressão) e os indicadores da obra. **Homologação da 6C**.

## 8. Riscos

- **Biblioteca de Gantt (D-P69):** é a peça visual mais complexa. Se a biblioteca livre não atender, o Gantt próprio atrasa a 6A.
- **Reprogramação automática:** cronogramas grandes (centenas de atividades) exigem o cálculo no servidor numa transação. Testo o desempenho com 500 atividades.
- **Dias úteis × dias corridos:** a duração em dias úteis difere do que o Brickup mostra. A tela exibe os dois para evitar confusão.
- **RDO com dados pessoais:** visitantes e funcionários. Só o necessário, com acesso por obra (LGPD, D-P07).
- **Fotos:** volume de armazenamento. Uso o limite de tamanho dos documentos; compressão de imagem fica para depois.
