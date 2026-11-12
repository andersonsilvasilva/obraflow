# PROMPT 002 — IDENTIDADE VISUAL, DESIGN SYSTEM E REFERÊNCIAS DE INTERFACE DO OBRAFLOW

Atue como Product Designer sênior, especialista em UX/UI para sistemas SaaS empresariais, e Front-end Architect.

Seu objetivo é estabelecer a identidade visual e as diretrizes de interface do ObraFlow, plataforma SaaS de gestão da construção civil desenvolvida pela HighTech Tecnologia.

IMPORTANTE: Antes de implementar qualquer tela, analise a documentação existente e todas as imagens de referência disponíveis em:

`documentation/references/telas/`

Essas imagens representam a direção visual e funcional desejada para o produto.

Não copie literalmente a identidade visual de outro sistema. Utilize as referências para compreender organização, hierarquia, funcionalidades e experiência de uso.

---

## 1. IDENTIDADE VISUAL OFICIAL

Nome: ObraFlow

Segmento: Gestão empresarial e operacional da construção civil.

Posicionamento: Plataforma moderna, tecnológica, profissional e inteligente para gestão de construtoras, obras, contratos, orçamentos e recursos.

A identidade visual deverá utilizar o AZUL-SAFIRA como cor principal.

### Paleta oficial

- Azul-safira principal: #0F52BA
- Azul-safira hover: #0B4298
- Azul profundo: #0A2E66
- Azul suave: #EAF1FF
- Grafite: #252A35
- Cinza claro: #F3F4F6
- Branco: #FFFFFF
- Verde de sucesso: #16A36A

### Regras obrigatórias

1. Não utilizar laranja como cor de destaque.
2. Não utilizar gradientes excessivos.
3. Utilizar azul-safira em botões primários, links, ícones ativos, seleções e indicadores.
4. Utilizar grafite para textos e elementos de navegação.
5. Utilizar fundos claros e neutros.
6. Utilizar verde apenas para estados positivos e confirmações.
7. Garantir contraste e acessibilidade.
8. Criar tokens de design centralizados para facilitar alterações futuras.

O azul-safira deverá ser a cor de identidade do produto em todos os módulos.

---

## 2. DIREÇÃO VISUAL DAS INTERFACES

A interface deverá seguir estes princípios:

- Design SaaS empresarial premium.
- Desktop-first, com responsividade para tablets e smartphones.
- Sidebar lateral com navegação organizada por módulos.
- Topbar compacto.
- Conteúdo principal com hierarquia visual clara.
- Cards de indicadores.
- Tabelas profissionais com boa densidade de informação.
- Formulários organizados e intuitivos.
- Filtros, pesquisas, ordenação e paginação.
- Abas para separar informações relacionadas.
- Modais e painéis laterais quando apropriado.
- Gráficos com visual limpo.
- Estados de carregamento, vazio, sucesso e erro.
- Feedback visual para todas as ações importantes.

Evitar excesso de espaços desperdiçados, elementos decorativos desnecessários e interfaces excessivamente simplificadas.

O sistema será utilizado por profissionais que trabalham diariamente com dados técnicos, financeiros e operacionais.

Priorizar produtividade, legibilidade e rapidez de operação.

---

## 3. ESTRUTURA DO LAYOUT PRINCIPAL

Criar uma estrutura visual compartilhada por todos os módulos.

### Sidebar

Navegação lateral com:

- Logo ObraFlow.
- Dashboard.
- Empresas.
- Clientes.
- Fornecedores.
- Obras.
- Contratos.
- Orçamentos.
- Planejamento.
- Diário de Obras.
- Medições.
- Financeiro.
- Compras.
- Suprimentos.
- Estoque.
- Equipes.
- Documentos.
- Relatórios.
- Configurações.

Organizar os módulos em grupos lógicos.

Permitir expansão e recolhimento do menu.

Destacar o módulo ativo utilizando azul-safira.

### Topbar

Deverá apresentar:

- Nome da empresa ou tenant ativo.
- Seletor de empresa, quando houver mais de uma.
- Seletor de obra, quando aplicável.
- Busca global.
- Notificações.
- Ajuda.
- Perfil do usuário.
- Menu de configurações pessoais.

### Área de conteúdo

- Breadcrumb.
- Título da página.
- Descrição curta.
- Botões de ação contextual.
- Indicadores, filtros, tabelas e formulários.

---

## 4. ANÁLISE DAS TELAS DE REFERÊNCIA

Analise individualmente todas as imagens disponíveis na pasta `documentation/references/telas/`.

Identifique:

- Estrutura do layout.
- Organização das informações.
- Navegação.
- Componentes.
- Campos.
- Tabelas.
- Ações disponíveis.
- Fluxos de trabalho.
- Indicadores.
- Recursos visuais.

As referências abrangem principalmente:

### Dashboard principal

Criar um painel de gestão com indicadores, gráficos e informações operacionais.

### Cadastro de orçamento

Contemplar:
- Orçamento por cliente.
- Orçamento manual.
- Orçamento com referência a tabelas técnicas.
- Identificação da obra.
- Dados do cliente.
- Responsáveis.
- Descrição e observações.

### Preenchimento do orçamento

Contemplar:
- Informações gerais.
- Grupos e etapas.
- Atividades e serviços.
- Composições de custos.
- Insumos.
- Mão de obra.
- Equipamentos.
- Quantidades.
- Custos unitários.
- Custos totais.
- BDI e encargos, conforme regras aplicáveis.

### Orçamento inteligente

Prever uma experiência assistida por IA para ajudar na elaboração e análise de orçamentos.

Não presumir que a IA possa validar automaticamente custos oficiais. Toda sugestão deverá permitir revisão e aprovação humana.

### Referências técnicas

Prever arquitetura para integração com tabelas como SINAPI e SUDECAP.

Não inventar valores oficiais ou composições. Os dados deverão ter origem, competência, localidade e versão identificáveis.

### Finalização do orçamento

Contemplar:
- Resumo financeiro.
- Composição dos custos.
- Visualização de recursos.
- Curva ABC.
- Diagramas.
- Indicadores.
- Exportação de relatórios.

### Suprimentos

Criar experiência para:
- Solicitações de materiais.
- Identificação da obra.
- Itens solicitados.
- Quantidades.
- Responsáveis.
- Situação da solicitação.
- Acompanhamento e aprovação.

---

## 5. DESIGN SYSTEM

Criar um Design System reutilizável em React + TypeScript.

Tecnologias já definidas:

- React.
- TypeScript.
- Vite.
- Tailwind CSS.
- shadcn/ui.
- React Router.
- TanStack Query.
- React Hook Form.
- Zod.

Criar componentes padronizados:

### Layout
- AppLayout
- Sidebar
- Topbar
- Breadcrumb
- PageHeader

### Indicadores
- StatCard
- MetricCard
- StatusBadge
- ProgressIndicator

### Formulários
- FormField
- Input
- Select
- DatePicker
- Textarea
- CurrencyInput
- SearchInput

### Tabelas
- DataTable
- TableToolbar
- TableFilters
- TablePagination
- TableActions

### Navegação
- Tabs
- DropdownMenu
- Breadcrumb
- Pagination

### Feedback
- Alert
- Toast
- ConfirmDialog
- EmptyState
- LoadingState
- ErrorState

### Orçamentos
- BudgetSummary
- BudgetGroup
- BudgetActivity
- CostComposition
- ResourceTable
- ABCCurve
- BudgetCharts

Os componentes deverão possuir tipagem consistente, boa reutilização e acessibilidade.

Não criar componentes gigantes ou misturar regras de negócio com apresentação.

---

## 6. TOKENS DE DESIGN

Centralizar as cores, tipografia, espaçamentos, bordas, sombras e estados.

Criar ou atualizar os arquivos de configuração do Tailwind e os tokens CSS.

Utilizar variáveis semânticas como:

- primary
- primary-hover
- primary-foreground
- background
- foreground
- card
- muted
- border
- success
- warning
- destructive

Não espalhar códigos hexadecimais diretamente pelos componentes.

O azul-safira deverá ser controlado por tokens globais.

---

## 7. DOCUMENTAÇÃO

Criar os seguintes documentos:

`documentation/DESIGN_SYSTEM.md`

Documentar:
- Paleta.
- Tipografia.
- Espaçamentos.
- Componentes.
- Estados.
- Responsividade.
- Acessibilidade.

`documentation/UX_GUIDELINES.md`

Documentar:
- Padrões de navegação.
- Hierarquia visual.
- Formulários.
- Tabelas.
- Modais.
- Feedback.
- Experiência do usuário.

`documentation/SCREEN_REFERENCES.md`

Documentar:
- Imagens analisadas.
- Características de cada tela.
- Funcionalidades identificadas.
- Decisões de adaptação para o ObraFlow.

Atualizar também:

- PROJECT.md
- AGENTS.md
- ARCHITECTURE.md

Registrar a identidade azul-safira como decisão oficial do projeto.

---

## 8. LIMITES DE EXECUÇÃO

Nesta tarefa:

1. Inspecionar as referências.
2. Documentar a identidade visual.
3. Criar o Design System.
4. Preparar os componentes compartilhados do frontend, se a estrutura do projeto já estiver pronta.
5. Não implementar módulos de negócio completos.
6. Não criar dados fictícios que pareçam dados reais.
7. Não alterar a arquitetura multi-tenant.
8. Não criar migrations.
9. Não implementar integrações externas.
10. Não remover ou sobrescrever arquivos sem inspeção prévia.

Se o frontend ainda não estiver inicializado, preparar a documentação e apresentar a estrutura recomendada, sem gerar uma aplicação paralela.

---

## 9. RELATÓRIO FINAL

Ao terminar, apresentar:

1. Referências analisadas.
2. Diretrizes visuais definidas.
3. Paleta aplicada.
4. Tokens criados.
5. Componentes implementados.
6. Documentos criados ou atualizados.
7. Pendências.
8. Recomendações para a próxima etapa.

Não iniciar o desenvolvimento funcional do sistema sem aprovação.

O resultado deverá estabelecer uma identidade visual consistente, profissional e escalável para todo o ObraFlow.