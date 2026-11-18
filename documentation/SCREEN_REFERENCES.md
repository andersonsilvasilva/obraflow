# SCREEN_REFERENCES.md — ANÁLISE DAS TELAS DE REFERÊNCIA

Pasta analisada: `documentation/telas/` — **todas as 24 imagens analisadas** (1–13 na fase de protótipo; 14 em 2026-11-16; 15–24 em 2026-11-16). O **padrão de layout e apresentação desejado pelo responsável** está em §"Padrão de layout e apresentação" e é **normativo** para todas as telas do ObraFlow. A pasta `planilha para importar recursos/` (planilhas `employee`, `equipment`, `fornecedores`, `product`) **não** contém telas e não foi analisada aqui.

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
| 14 | `Cadastro de Produtos.png` | Listagem "Materiais" dentro de Cadastros → Recursos | Busca por nome; filtros por grupo e subgrupo; tabela com seleção, nome (ordenável), unidade de medida, grupo, subgrupo e preço; ações por linha; "Adicionar"; paginação | **Não implementada** (ver §"Cadastro de Produtos"). Relaciona-se a `units_of_measure` (Fase 2) e a um catálogo de recursos/insumos ainda **não modelado** |
| 15 | `cadastro_recursos_cargo_funcao.png` | Listagem "Cargo/Função" em Cadastros → Recursos | Busca por nome; "+ Adicionar"; tabela Nome (ordenável) e Preço (R$); ações por linha (pasta, editar, excluir); paginação com "10 / Página" | Padrão de **listagem** (§Padrão, L1). Insumo para a **Fase 3** (cargos/funções de equipes) |
| 16 | `Cadastro de Recursos.png` | Formulário "Nova mão de obra" com **modal de cadastro rápido** de Cargo/Função | Campo obrigatório (asterisco), seleção de cargo; bloco recolhível "Mais informações"; modal com campo + "Adicionar" na mesma linha e lista com editar/excluir | Padrão de **cadastro rápido de entidade auxiliar** sem sair do formulário (§Padrão, F4). Insumo para a Fase 3 |
| 17 | `Cadastro de Recursos_balanco.png` | Modal "Balanço" (sobre a lista de mão de obra, sobre outro modal) | Salário base (R$), hora adicional (R$), encargos (%), administração (%), carga horária mensal (com ajuda), mão de obra Indireta/Direta (rádio), tipo de contrato (Mensalista/Horista/Empreitada/Diarista), data; lista com "Exportar" e "250 / Página" | Padrão de **formulário com rótulos à esquerda** e **rádio para escolha binária** (§Padrão). Campos são insumo para a Fase 3 (custo de mão de obra, a decidir no modelo). Modal sobre modal **não** adotado (§Padrão, F6) |
| 18 | `Perfil_Empresa.png` | Configurações → Empresa | Menu secundário vertical; formulário em grupos com título e linha divisória (Endereço, Informações básicas, Contatos); máscaras como exemplo no campo (CEP, CPF/CNPJ, telefone); painel de logo à direita com enviar/remover e orientação; "Atualizar" no fim | Padrão de **página de configurações** (§Padrão, C1–C2). Relaciona-se a `tenants`/`companies` (Fase 1/2) |
| 19 | `Perfil_usuarios.png` | Configurações → Usuários, com modal "Editar usuário" | Contador "N usuários contratados"; "Adicionar usuário" bloqueado (cadeado); tabela Nome, Cargo/Função, Setores, Status (ordenar/filtrar), editar; modal em 2 colunas com rótulo acima: primeiro/último nome, e-mail (desabilitado), telefone, "Usuário externo" (ajuda), cargo/função, setor, grupos (multisseleção); Cancelar/Atualizar | Padrão de **modal de edição** (§Padrão, F1–F3). Cargo/função e setor do usuário são insumo da Fase 3; "grupos" ≈ papéis (Fase 1) |
| 20 | `Perfil_Regras_de_Negocio.png` | Regras de negócio → aba "Permissões de edição" | Abas horizontais; **painéis recolhíveis** com título em que a palavra-chave vem em negrito; frase explicativa; seletores de grupo por status; **aviso amarelo** explicando o comportamento quando nada é selecionado | Padrão **abas + painéis recolhíveis + aviso de padrão** (§Padrão, C3–C4). Regras por status pertencem a fases futuras (suprimentos) |
| 21 | `Perfil_Regras_de_Negocio_aprovaçao.png` | Aba "Aprovações" | Faixas de valor ("De R$0,00 até … não precisa de aprovação", "+" para nova faixa; "Maior que … poderá ser aprovada pelos usuários"); 1ª e 2ª aprovação por grupo; "Salvar" no bloco | Padrão de **regra escrita como frase com campos embutidos** (§Padrão, C5). Alçadas de aprovação: fase futura |
| 22 | `Perfil_Regras_de_Negocio_preferencias.png` | Aba "Preferências" | Casas decimais de quantidade com "Salvar" ao lado; fornecedores padrão por imposto (INSS, ISS, Unificado PIS/COFINS/CSLL, IRRF, Retenção técnica) em grade de 3 colunas | Padrão de **grade de seletores em 3 colunas** (§Padrão). Preferências fiscais: fase futura |
| 23 | `Perfil_Regras_de_Negocio_Integraçoes.png` | Configurações → Integrações | Grade de cartões com logo, descrição e "Saiba mais" | Padrão de **cartões em grade** (§Padrão, C6). **Nenhuma integração** será criada sem decisão (AGENTS.md §8) |
| 24 | `Perfil_Automaçao.png` | Configurações → Automação | Lista de regras de notificação por e-mail: descrição, destinatários por papel (caixas), seleção de usuários, **interruptor** à direita; cadeado em regras indisponíveis | Padrão de **lista de regras com interruptor** (§Padrão, C7). Automação: fase futura |

## Cadastro de Produtos (`Cadastro de Produtos.png`) — análise de 2026-11-16

Somente o que está **visível** na captura; nada foi implementado (decisão do responsável, 2026-11-16).

**Contexto de navegação**
- Página intitulada **"Materiais"** (o arquivo se chama "Cadastro de Produtos"; a URL da captura termina em `/products`).
- Menu lateral: grupo **Cadastros → Recursos**, com os subitens **Materiais** (selecionado), Mão de obra, Cargo/Função, Fornecedores, Equipamentos e Serviços. Outros itens de menu visíveis: Tarefas, Apropriação, Orçamentos, Financeiro, Requisições, Dashboard, RH.
- Topo do menu: seletor da organização ativa; rodapé: cartão do usuário logado.

**Barra de ações (acima da tabela)**
| Elemento | Tipo | Observação |
|---|---|---|
| "Excluir tudo" | botão (aparência desabilitada) | presumivelmente atua sobre a seleção — **não confirmado** pela imagem |
| "Buscar por nome" | campo de busca com ícone de lupa | |
| "Selecione um grupo" | lista suspensa | filtro |
| "Selecione um subgrupo" | lista suspensa | filtro |
| ícone de seta para cima | botão só com ícone | função **não identificável** pela imagem (pode ser importar ou exportar) |
| "+ Adicionar" | botão primário | abre criação (o formulário não aparece na captura) |

**Tabela**
| Coluna | Observação |
|---|---|
| (caixa de seleção) | seleção por linha e no cabeçalho |
| Nome | com indicador de ordenação |
| Unidade de medida | exemplos: m³, sc, und; **vazia** em vários itens |
| Grupo | exemplo: "Outros"; vazia em vários itens |
| Subgrupo | vazia em todos os itens visíveis |
| Preço (R$) | valor único por item, formato `R$ 0,00` (itens com R$ 0,00) |
| Ações | três ícones por linha: pasta, lápis (editar), lixeira (excluir). O significado do ícone de **pasta não é identificável** |

**Rodapé:** paginação (páginas 1 e 2, anterior/próxima) e seletor "10 / Página".

**Organização visual:** página em cartão branco único; barra de ações à esquerda e "Adicionar" à direita; tabela densa com linhas de altura uniforme; nomes de itens às vezes em caixa alta (dados importados).

**Relação com o ObraFlow (sem implementar)**
- Unidade de medida já existe por tenant (`units_of_measure`, D-P14); um futuro cadastro de materiais a referenciaria.
- Grupo/subgrupo, preço e o próprio catálogo de materiais **não existem** no modelo atual; dependem da fase de recursos/insumos (orçamentos, Fase 4) e de aprovação.
- "Fornecedores" aparece como subitem de Recursos na referência; no ObraFlow, fornecedores já são cadastro da Fase 2 (vínculo com materiais **não** está modelado).
- Adaptação segue as decisões gerais deste documento (identidade azul-safira, dados `DEMO-*`, sem valores oficiais).

## Padrão de layout e apresentação (definido pelo responsável, 2026-11-16)

**Normativo.** O responsável definiu que o layout e a apresentação das telas de referência são o padrão desejado para o ObraFlow. Toda tela nova segue as regras abaixo. **Cores, marca e logo continuam sendo os do ObraFlow** ([DESIGN_SYSTEM.md](DESIGN_SYSTEM.md): azul-safira no lugar do laranja das capturas; sidebar grafite). O que se adota é a **estrutura, a hierarquia e o comportamento**, não a identidade do produto de terceiros.

### Estrutura geral (G)
- **G1 — Moldura:** sidebar fixa à esquerda + área de conteúdo com fundo cinza-claro; o conteúdo vive em **um cartão branco** de cantos suaves e borda fina.
- **G2 — Sidebar:** topo com **seletor da organização (tenant) ativa** e botão de recolher; itens por módulo com ícone + rótulo; grupos expansíveis em até 3 níveis (ex.: Cadastros → Recursos → Materiais); item ativo com fundo tingido e texto na cor de destaque; selos numéricos/alerta no ícone quando houver pendências; rodapé com **cartão do usuário** (iniciais, nome, e-mail abreviado) que abre o menu da conta.
- **G3 — Sidebar recolhida:** só ícones (com selos), avatar do usuário no rodapé; usada em páginas largas como Configurações.
- **G4 — Cabeçalho da página:** título grande à esquerda (ex.: "Cargo/Função"), linha separadora fina abaixo; ações da página dentro do cartão, não no cabeçalho.
- **G5 — Densidade e tipografia:** muito espaço em branco, textos de tabela pequenos, uma única cor de destaque para ação principal e estado ativo; botões secundários com contorno; ícones finos de linha.

### Listagens (L) — padrão dos cadastros
- **L1 — Barra de ferramentas:** à esquerda **busca "Buscar por nome"** com ícone de lupa, seguida dos **filtros em lista suspensa** ("Selecione um grupo") e de botões só-ícone (importar/exportar); à direita o botão principal **"+ Adicionar"**.
- **L2 — Ação em massa:** quando houver seleção por linha, botão "Excluir tudo" à esquerda da barra, **desabilitado** sem seleção (exclusão em massa exige confirmação e respeita bloqueio por uso — erro `in_use`).
- **L3 — Tabela:** cabeçalho cinza-claro em negrito; caixa de seleção opcional na 1ª coluna; colunas ordenáveis com indicador ▲▼; filtro por coluna (funil) quando útil; linhas de altura uniforme separadas por linha fina; valores monetários como `R$ 0,00`; campos vazios em branco.
- **L4 — Ações por linha:** coluna final com botões só-ícone, nesta ordem: **documentos/anexos** (ícone de pasta — no ObraFlow, abre os documentos vinculados, Fase 2), **editar** (lápis), **excluir** (lixeira, com confirmação). Cada ícone com rótulo acessível e dica.
- **L5 — Paginação:** abaixo da tabela, à direita: anterior/próxima, números de página com a atual destacada e seletor "**10 / Página**" (opções maiores para listas longas).
- **L6 — Rolagem:** a tabela rola dentro do cartão quando excede a altura; cabeçalho fixo.

### Formulários (F)
- **F1 — Modal de edição:** título à esquerda ("Editar usuário"), **X** à direita; campos em **2 colunas** com **rótulo acima**; obrigatórios com **asterisco vermelho** antes do rótulo; rodapé à direita com **Cancelar** (secundário) e ação principal (**Atualizar**/**Salvar**/**Criar**).
- **F2 — Campos:** texto de exemplo no próprio campo indicando a máscara (CEP `11111-111`, CPF/CNPJ, telefone); campo não editável aparece **desabilitado** (cinza) em vez de oculto; ícone **(?)** ao lado do rótulo com explicação; listas suspensas com "Selecione…"; multisseleção para grupos/usuários.
- **F3 — Escolhas:** **rádio** para escolha exclusiva curta (ex.: Indireta/Direta), **lista suspensa** para enumerações maiores (tipo de contrato), **caixa de seleção** para opções independentes, **interruptor** para ativar/desativar regra.
- **F4 — Cadastro rápido:** entidade auxiliar (ex.: Cargo/Função) é criada/editada num **modal sobre o formulário**, com campo + "Adicionar" na mesma linha e a lista existente com editar/excluir — sem perder o que já foi digitado.
- **F5 — Página de criação:** formulários longos em página própria ("Nova mão de obra"), campos essenciais primeiro e bloco recolhível **"Mais informações"** para os opcionais.
- **F6 — Profundidade:** no máximo **um modal sobre outro** (cadastro rápido). Modal sobre modal sobre lista (como no `Balanço`) **não** é adotado: o segundo nível vira página ou painel lateral.
- **F7 — Rótulos à esquerda:** permitidos só em formulários de configuração largos (rótulo alinhado à direita com dois-pontos, campo ocupando o resto); em telas estreitas os rótulos voltam para cima.

### Configurações (C)
- **C1 — Menu secundário vertical** dentro do conteúdo (Empresa, Usuários, Automação, Regras de negócio, Integrações), item ativo preenchido com a cor de destaque; conteúdo em cartão à direita.
- **C2 — Grupos de campos** com título e linha divisória (Endereço, Informações básicas, Contatos); painel lateral à direita para mídia (logo: pré-visualização, enviar, remover e orientação de formato); botão de gravar no fim, à direita.
- **C3 — Abas horizontais** sublinhadas para subdividir uma seção (Permissões de edição / Aprovações / Preferências).
- **C4 — Painéis recolhíveis** (acordeão) com seta e título em que a palavra-chave vem em **negrito**; dentro, uma frase explicativa em negrito, os campos e um **aviso amarelo** com ícone explicando o comportamento padrão ("Se nenhuma opção for selecionada…").
- **C5 — Regra como frase:** campos embutidos no texto ("De R$0,00 até [ ] não precisa de aprovação", "+" para nova faixa); botão **Salvar por bloco**.
- **C6 — Cartões em grade** (3 por linha) com logo/ícone, descrição curta e botão.
- **C7 — Lista de regras com interruptor** à direita de cada regra; regra indisponível exibe **cadeado** e fica desabilitada.
- **C8 — Contadores de contexto** acima da tabela ("1 usuário contratado"); ação indisponível aparece desabilitada com cadeado, não some.

### Ajustes obrigatórios do ObraFlow sobre o padrão
1. **Identidade:** azul-safira/grafite, logo e nome do ObraFlow; nada de laranja, nome ou logo do produto de referência.
2. **Acessibilidade:** botões só-ícone com `aria-label` e dica; modais com foco preso e Esc; tabela com `caption`/`scope`; aviso amarelo com texto (não só cor); contraste AA.
3. **Responsividade:** em telas estreitas, sidebar vira gaveta, barra de ferramentas quebra em linhas, tabela permite rolagem horizontal e modais de 2 colunas viram 1.
4. **Estados:** toda listagem tem carregando, vazio (com chamada para "Adicionar") e erro ([DESIGN_SYSTEM.md](DESIGN_SYSTEM.md) §6).
5. **Escopo:** o padrão define **como** as telas se apresentam; **não** autoriza as funcionalidades vistas nas capturas (aprovações por faixa, automações por e-mail, integrações, impostos, elementos comerciais como "Central de Aprendizado"/"Fazer Upgrade"), que continuam dependendo de fase e aprovação.
6. **Permissões:** ações sem permissão aparecem **desabilitadas** (ou ocultas quando a própria existência for sensível); a API continua sendo a autoridade (403/404).

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

## Referências do Brickup (vídeo de treinamento, 2026-11-17)

Enviadas pelo responsável em vídeo do Brickup, o sistema que ele usa hoje. Os prints **não** ficam no repositório, porque mostram nome e e-mail de terceiros. Usamos a organização e os campos como referência; a aparência segue a identidade do ObraFlow.

| Tela | O que mostra | Onde entra |
|---|---|---|
| Obra → **Calendário** | Lista de exceções (Descrição, Data, Tipo, Criado por) ao lado do calendário Mensal/Semanal/Diário; "Importar sábados e domingos como exceções"; "Nova exceção" (Descrição, Data, Tipo Única/Recorrente, "Repetir a cada N dia/semana/quinzena/mês/ano") | [CALENDARIO_OBRA_MODELO.md](CALENDARIO_OBRA_MODELO.md) |
| Obra → **Horários** | Grade domingo–sábado × Entrada 1, Saída 1, Entrada 2, Saída 2; "Atualizar" e "Atualizar e sair" | [CALENDARIO_OBRA_MODELO.md](CALENDARIO_OBRA_MODELO.md) (D-P63) |
| Obra → **Contratos** | Lista Contrato, Descrição, Valor, Observações, Arquivos, Excluir; "Adicionar contrato" | [FASE5_MODELO.md](FASE5_MODELO.md) (A-45) |
| Obra → Execução → Contratos | Contratos no menu de execução da obra (bloqueado no plano do responsável) | [FASE5_MODELO.md](FASE5_MODELO.md) |
| Obra → **Planejamento** → Atividades / Recursos | Gantt: tabela (ID, Atividade, Data de início, Data de término, Duração) + barras por mês; "Planejamento atual" × "Comparar com" (linhas de base, "Criar linha de base 0/10"); ações Adicionar/Remover atividade, Deletar tudo, Expandir/Recolher, **Caminho crítico**, Exportar, zoom | **Fase 6 — Planejamento** (ainda não proposta). O calendário e os horários da obra alimentam os dias úteis do cronograma |

**Mais detalhes do Planejamento (Brickup, para a Fase 6):**
- **Atividades em níveis** (1, 1.1, 1.1.1), com "+" em cada linha para criar uma subetapa. A etapa-mãe cobre o período das filhas.
- **Duração** editável na linha (em dias corridos entre início e término). As barras do Gantt podem ser arrastadas. Linha vertical "Hoje".
- **Aba Recursos** do planejamento: a mesma árvore de atividades com os insumos de cada uma.
  - Modal "Adicionar item" por tipo: Materiais (multisseleção), Equipamento, Tipos de equipamentos, Cargo/Função, Outros, Serviços.
  - Colunas: Tipo, Unidade, Qtd. unitária, Qtd. total, Valor de venda unitário, Valor de custo unitário, Total de venda, Total de custo, com os totais do planejamento no topo.
  - Usa o catálogo de insumos (Fase 4C) e os cargos (Fase 3).
- **Criar o planejamento** (modal "Escolha o formato"): **Inteligência Artificial** (com limite de créditos por plano; liga-se à Fase 9 — IA assistiva), **Importar planilha**, **Usar modelo pronto** e **Criar meu próprio**. Botões "Criar linha de base (0/10)" e "Adicionar".
- **Obra → Geral** (página inicial da obra): indicadores Financeiro (saúde financeira, custo total) e Métricas (RDOs criados, dias decorridos, efetivos, atividades atrasadas, execução %). O menu da obra tem Geral, RDO, Planejamento, Execução, Requisições, Ocorrências e Linha do Tempo.
- **Obras (painel)**: abas Todas/Ativas/Inativas; busca e filtros (cliente, responsável técnico, encarregado); indicadores no topo; alternância entre lista e cards; card com responsável técnico, encarregado, % executada, dias decorridos, saúde financeira, valor apropriado, previsão de entrega, atividades atrasadas, efetivos, "Acessar" e menu ⋮ (status, editar, excluir, acompanhamento financeiro, cotações, RDO, efetivos). Implementado em A-46.
- **Importar planilha do planejamento** (modal "Upload Excel"): "Adicionar arquivo", link para baixar a planilha-modelo e o bloco "Formatos corretos".
  - Tipos de coluna: A–E e L são texto; F–I são datas; J, K, M e N são números.
  - Recursos devem ficar sob uma etapa. O item é separado por caracteres não alfanuméricos, em ordem. A descrição tem no máximo 250 caracteres.
  - Equipamento e mão de obra exigem a coluna C; material, indiretos e serviços exigem C e L.
  - **Planilha-modelo `planning.xlsx`** (colunas abaixo).
- **Planejamento (versão nova do Brickup):**
  - totais no topo: Custo, Com BDI, BDI, Desconto, Valor final e venda por tipo (material, equipamentos, cargo/função, serviço, outros);
  - botões Caminho crítico, Linha de base, **BDI**, **Desconto**, Importar, Exportar, **Exceções** (o calendário da obra) e "Planejamento com IA (1/2)";
  - colunas editáveis (Atividade, Início, Término, Duração em dias) e Gantt diário;
  - interruptor "Visualizar recursos".
  - **Configurações de visualização do cronograma:**
    - modelos Padrão, Cronograma, Custo detalhado, Venda detalhada e Personalizado;
    - colunas visíveis no grupo Básico: ID, Atividade, Início, Término, Duração, Predecessoras, Unidade, Qtd. unitária, Qtd. total, Valor de custo unitário, BDI %, Desconto %, Valor de venda unitário, Total de custo, Total de venda e Recursos; há também um grupo Avançado;
    - **modo de visualização:** Somente tabela, Layout dividido ou Somente Gantt;
    - botões "Restaurar largura/ordem das colunas" e "Salvar preferências" (por usuário).
- **Orçamento sintético do planejamento** (exportação do Brickup; o arquivo do responsável não fica no repositório). São 28 colunas por atividade:
  - **Identificação:** ID, Atividade, Un. medida, Qtd. unit.
  - **Custo unitário:** Valor de custo unit. e o custo unitário por tipo de insumo (material, equipamento, cargo/função, serviço, outros).
  - **Percentuais:** BDI (%) e Desconto (%).
  - **Venda unitária** por tipo de insumo (os mesmos 5 tipos).
  - **Total de custo** por tipo de insumo.
  - **Total de venda** e total de venda por tipo de insumo.
  - Na Fase 6, cada atividade soma os recursos dela separados por tipo. Os tipos já existem no catálogo (4C: material, mão de obra, equipamento, serviço), "cargo/função" é a mão de obra e "outros" fica para os demais. BDI e desconto seguem a mesma conta do orçamento (D-P02).
- **Ações por linha do planejamento** (barra que aparece sobre a linha): Atividade, **Sub ativ.**, **Comp. própria**, **Comp. pública** (SINAPI), **Recursos** e Excluir. É como cada atividade recebe custo (6B).
- **Composição simplificada** (Planejamento → Composições Próprias, painel lateral):
  - Nome*, Unidade de medida*, Data de referência* e Estado (UF);
  - valor por tipo: **Materiais**, **Serviços** e **Outros** com um valor; **Equipamentos** e **Cargo/Função** com **Valor sem encargos**, **Valor desonerado** e **Valor não desonerado**;
  - rodapé com **Valor total sem encargos / desonerado / não desonerado**.
  - Referência para as composições próprias (D-P42, adiada na 4C) dentro da 6B.
- **RDO — Diário de Obra** (um por dia, navegação por data, "Exportar"):
  - cabeçalho com obra, endereço, responsável técnico, encarregado, data de início e prazo; Nº do RDO; "Aprovado por" + botão **Aprovar**;
  - **condição climática** de manhã, tarde e noite (sol, nublado, chuva);
  - Mão de obra, Equipamento e **Horas produtivas** (vindas do horário da obra, ex.: 06:00–11:00 e 12:00–15:00);
  - Atividades (por quantidade ou %) e Atividades paralisadas;
  - Materiais recebidos e utilizados;
  - Ocorrências, Comentários gerais, Visitas e Fotos, cada bloco com botão "Entrada".
  - Entra na **Fase 6** (Diário de obras), com os horários e o calendário da obra já feitos.
- Colunas da planilha-modelo `planning.xlsx`: Tipo (Etapa/Material/Serviços…), Item (1, 1.1, 1.1.1…), Descrição, Data de início (planejado), Data de entrega (planejado), Início da etapa (executado), Conclusão da etapa (executado), Valor unitário, Quantidade, Unidade, HH prevista, Comentário.
