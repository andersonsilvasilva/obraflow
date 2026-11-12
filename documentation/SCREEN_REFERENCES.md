# SCREEN_REFERENCES.md — ANÁLISE DAS TELAS DE REFERÊNCIA

Pasta analisada: `documentation/telas/` — **13 imagens analisadas**. A pasta tem hoje **22**: as 9 adicionadas depois (*Cadastro de Recursos* e *Cadastro de Recursos_balanco*; *Perfil_Automaçao*, *Perfil_Empresa*, *Perfil_Regras_de_Negocio* e suas variantes *integraçoes*/*aprovaçao*/*preferencias*, *Perfil_usuarios*) **ainda NÃO foram analisadas** (relevantes para recursos, perfis/regras de aprovação e usuários/permissões). O `telas_modelos.zip` foi removido pelo usuário.

> **As referências são capturas de um produto de terceiros** (identidade laranja, nome e logo próprios, conta de usuário visível). Foram usadas **somente** para entender organização, hierarquia, funcionalidades e fluxo. **Não** foram copiados identidade, nome, logo, cores, textos de marca nem códigos/atividades de exemplo. Os dados do ObraFlow são fictícios (`DEMO-*`).

## Imagens analisadas

| # | Arquivo | O que mostra | Funcionalidades identificadas | Adaptação no ObraFlow |
|---|---|---|---|---|
| 1 | `dashboard_principal.png` | Lista de obras (grade/lista) com cartões de indicadores e filtros | Abas Todas/Ativas/Inativas; busca; filtros cliente/responsável; indicadores (obras, despesas/receitas do dia e em aberto, saúde financeira, pedidos, cotações pendentes); cartão de obra com progresso e "Acessar"; sidebar por módulos | **Dashboard** executivo com KPIs, 5 gráficos, alertas e atividades (`features/dashboard`); **Obras** em tabela com filtros (cliente, responsável, situação, período) e progresso (`features/projects`). Grade/lista e abas de situação ficam como evolução |
| 2 | `cadastrar orçamento.png` | Tela "Orçamento Manual" com guia de onboarding | Título, data, status (desabilitado), estado, encargo social, bloco opcional "Mais informações", atalhos "Criar orçamento inteligente" e "Ver todos" | `BudgetCreatePage`: tipo (cliente/manual/referência), campos gerais, **Estado** e **Encargos sociais** (regime a confirmar — D-P03), ações Cancelar/Criar. Guia de onboarding não adotado |
| 3 | `cadastrar orçamento Cliente.png` | Painel lateral "Cadastrar novo cliente" sobre o formulário | Cadastro rápido de cliente sem sair do fluxo (tipo, nome) | Padrão de **painel lateral** (`Modal variant="side"`) já disponível; cadastro rápido embutido no orçamento fica como evolução |
| 4 | `cadastrar orçamento manual SUDECAP.png` | Formulário expandido | Cliente, anexos (arquivos), opção "usar base de referência", observações | Campo de **referência técnica** como tipo de orçamento; **sem** valores oficiais nesta etapa (integração SINAPI/SUDECAP vedada) |
| 5 | `Preencher Orçamento.png` | Orçamento em abas, vazio | Abas Informações Básicas / Orçamento / Curva ABC / Curva ABC (Recursos) / Diagramas; KPIs (custo, com BDI, BDI, desconto, valor final); botões BDI, Desconto, Importar, Exportar; **Aprovar/Reprovar**; "Orçamento com IA" com cota; estado vazio | `BudgetEditorPage` + `BudgetFinalizationPage`: resumo (custo direto, BDI, total), Aprovar/Reprovar (demonstrativos), estado vazio. Desconto global implementado; Importar e abas unificadas ficam como pendência |
| 6 | `Preencher Orçamento_atividades.png` | Primeira atividade sendo editada em linha | Edição inline; colunas ID, atividade, unidade, quantidade, custo unit., BDI %, desconto %, total de venda | Tabela de itens com edição por modal (acessível) e colunas equivalentes (sem BDI/desconto por item ainda) |
| 7 | `Preencher Orçamento_orçamento_inteligente.png` | Assistente "Orçamento Inteligente" | Descrição rica do projeto, exemplos rápidos, **padrão de acabamento** baixo/médio/alto, "Gerar planejamento" | `SmartBudgetPage`: descrição, informações técnicas, **padrão de acabamento**, **sugestões fixas e rotuladas como demonstração**, aceitar/rejeitar. **Sem IA real**; valores de CUB do exemplo **não** reproduzidos |
| 8 | `Preencher Orçamento_orçamento_inteligente_LBMS.png` | Tela de progresso da análise (explica LBMS) | Etapa de análise com barra e tempo | Não implementada; relevante para a futura fase de IA/planejamento (linha de balanço) |
| 9 | `Finalização do orçamento.png` | Orçamento preenchido em árvore hierárquica | EAP em níveis (1, 1.1, 1.1.1), expandir/recolher, totais por nível, KPIs preenchidos | Etapas → itens em dois níveis (`BudgetGroup`/`BudgetItem`); **árvore multinível expansível** fica como evolução (modelo de dados já prevê `budget_groups.parent_id`) |
| 10 | `Finalização do orçamento_curvaABC.png` | Curva ABC em tabela | Quantidade, custo/venda unit. e total, % e % acumulada, faixa A/B/C com destaque de cor | `ABC` na finalização: total, % item, % acumulado, classe A/B/C (`features/budgets/calc.ts`, testado) |
| 11 | `Finalização do orçamento_curvaABC_diagramas.png` | Curva de Pareto e **Curva S** | Pareto (barras + % acumulado) e curva S temporal | Pareto implementado; **Curva S** depende do cronograma (pendência) |
| 12 | `Finalização do orçamento_visualizar recursos.png` | Toggle "Visualizar recursos" | KPIs extras por tipo (material, equipamentos, mão de obra, serviços) | Aba **Recursos** (consolidação por insumo) e gráfico por tipo de recurso |
| 13 | `suprimentos_solicaitação.png` | Lista de solicitações de suprimentos | Filtros (descrição, ID, obra, status, período), colunas ID/descrição/obra/itens/criado por/status, ações por linha, paginação e total | `ProcurementPage`: busca, filtro de situação, tabela (número, obra, solicitante, data, itens, prioridade, situação), formulário de solicitação com itens dinâmicos |

## Padrões transversais extraídos

- Sidebar agrupada por módulos, recolhível; breadcrumb; título + ações contextuais.
- Cartões de indicadores no topo; tabelas densas com filtros acima; estados vazios com ilustração e chamada à ação.
- Formulários com campos obrigatórios marcados e blocos opcionais recolhíveis; painéis laterais para cadastro rápido.
- Aprovação/reprovação como ação explícita do orçamento.
- Orçamento inteligente como fluxo dedicado, com limite de uso (cota) — a modelar em `ai_usage_logs`.

## Decisões de adaptação

1. **Identidade própria:** azul-safira, sidebar grafite, sem laranja ([DESIGN_SYSTEM.md](DESIGN_SYSTEM.md)).
2. **Dados fictícios inequívocos:** códigos `DEMO-*`, nomes "Cliente Demo", banner "Dados de demonstração" nas telas.
3. **Sem valores oficiais:** nenhuma composição/preço de SINAPI/SUDECAP; campos de fonte/competência/localidade/versão planejados no modelo ([DATABASE.md](DATABASE.md) §2.7).
4. **IA assistiva, nunca decisória:** sugestões rotuladas, com aceitar/rejeitar humano.
5. **Acessibilidade:** edição por modal em vez de edição inline apenas por mouse; tabelas com `caption`/`scope`; abas com navegação por setas.

## Lacunas conhecidas vs. referências

Árvore EAP multinível expansível · Desconto por item (o global já existe) · Importar/Exportar reais · Curva S · Curva ABC de recursos separada · grade de obras · cadastro rápido de cliente no orçamento · progresso de análise de IA · cota de uso de IA.
