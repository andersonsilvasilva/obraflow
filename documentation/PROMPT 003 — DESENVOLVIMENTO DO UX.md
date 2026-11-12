# PROMPT 003 — DESENVOLVIMENTO DO PROTÓTIPO NAVEGÁVEL DO OBRAFLOW

Atue como Engenheiro Front-end Sênior, Arquiteto React e Especialista em UX/UI para plataformas SaaS empresariais.

Sua missão é desenvolver o primeiro protótipo navegável do ObraFlow, plataforma SaaS de gestão da construção civil da HighTech Tecnologia.

Este trabalho deverá transformar a documentação e as referências visuais aprovadas em uma aplicação frontend organizada, funcional e preparada para integração futura com o backend Laravel.

# 1. CONTEXTO DO PROJETO

Nome: ObraFlow

Segmento: SaaS de gestão da construção civil.

Público:
- Construtoras.
- Incorporadoras.
- Engenheiros civis.
- Arquitetos.
- Gestores de obras.
- Orçamentistas.
- Compradores.
- Profissionais administrativos e financeiros.

O sistema deverá permitir administrar empresas, obras, contratos, orçamentos, planejamento, equipes, compras, suprimentos, documentos e finanças.

A aplicação deverá ser preparada para funcionar como plataforma multi-tenant.

# 2. DOCUMENTAÇÃO OBRIGATÓRIA

Antes de implementar:

1. Ler PROJECT.md.
2. Ler AGENTS.md.
3. Ler ARCHITECTURE.md.
4. Ler DESIGN_SYSTEM.md, caso já exista.
5. Ler UX_GUIDELINES.md, caso já exista.
6. Ler SCREEN_REFERENCES.md, caso já exista.
7. Analisar todas as imagens existentes em `documentation/references/telas/`.
8. Inspecionar a estrutura atual de `obraflow-web/`.

Não sobrescrever arquivos existentes sem inspeção.

Respeitar as decisões arquiteturais já aprovadas.

Se houver divergência entre documentação e código, registrar a divergência e propor uma solução antes de alterar decisões importantes.

# 3. STACK FRONTEND

Utilizar:

- React.
- TypeScript.
- Vite.
- Tailwind CSS.
- shadcn/ui.
- React Router.
- TanStack Query.
- React Hook Form.
- Zod.
- Lucide React para ícones.

Utilizar versões estáveis e compatíveis entre si.

Não instalar bibliotecas redundantes.

Não misturar bibliotecas de componentes com padrões visuais incompatíveis.

# 4. IDENTIDADE VISUAL OFICIAL

A identidade visual obrigatória é AZUL-SAFIRA.

Paleta:

- Azul-safira principal: #0F52BA.
- Azul-safira hover: #0B4298.
- Azul profundo: #0A2E66.
- Azul suave: #EAF1FF.
- Grafite: #252A35.
- Cinza claro: #F3F4F6.
- Branco: #FFFFFF.
- Verde de sucesso: #16A36A.

REGRAS:

- Não utilizar laranja como cor de destaque.
- Utilizar azul-safira para botões primários, links, seleções e estados ativos.
- Utilizar fundos neutros.
- Criar tokens de design centralizados.
- Manter contraste adequado.
- Garantir consistência entre todas as telas.

# 5. ARQUITETURA DA APLICAÇÃO

Criar uma estrutura modular.

Organização sugerida:

src/
├── app/
│   ├── router/
│   ├── providers/
│   └── layouts/
│
├── assets/
├── components/
│   ├── ui/
│   ├── layout/
│   ├── data-display/
│   ├── forms/
│   └── feedback/
│
├── features/
│   ├── dashboard/
│   ├── companies/
│   ├── customers/
│   ├── suppliers/
│   ├── projects/
│   ├── contracts/
│   ├── budgets/
│   ├── planning/
│   ├── construction-diary/
│   ├── measurements/
│   ├── finance/
│   ├── procurement/
│   ├── inventory/
│   ├── teams/
│   ├── documents/
│   ├── reports/
│   └── settings/
│
├── hooks/
├── lib/
├── services/
├── mocks/
├── types/
├── utils/
└── styles/

Adaptar a estrutura caso já exista uma organização aprovada.

Cada feature deverá manter seus componentes, tipos, serviços e regras de apresentação organizados.

# 6. LAYOUT PRINCIPAL

Implementar um layout administrativo profissional.

## Sidebar

Criar menu lateral com:

DASHBOARD
- Visão geral.

GESTÃO
- Empresas.
- Clientes.
- Fornecedores.
- Obras.
- Contratos.

ENGENHARIA
- Orçamentos.
- Planejamento.
- Diário de Obras.
- Medições.

OPERAÇÃO
- Compras.
- Suprimentos.
- Estoque.
- Equipes.

ADMINISTRAÇÃO
- Financeiro.
- Documentos.
- Relatórios.

CONFIGURAÇÕES
- Minha conta.
- Usuários e permissões.
- Configurações da empresa.

A sidebar deverá possuir:
- Logo ObraFlow.
- Ícones.
- Navegação agrupada.
- Item ativo destacado em azul-safira.
- Opção de recolher e expandir.
- Comportamento responsivo.

## Topbar

Incluir:

- Seletor de empresa/tenant.
- Seletor de obra, quando aplicável.
- Pesquisa global.
- Notificações.
- Ajuda.
- Perfil do usuário.

Os seletores deverão ser demonstrativos nesta etapa.

# 7. TELAS OBRIGATÓRIAS DO PROTÓTIPO

Implementar as seguintes telas com navegação funcional.

## 7.1 Dashboard principal

Criar dashboard executivo com:

- Total de obras.
- Obras em andamento.
- Obras concluídas.
- Obras em atraso.
- Orçamento total.
- Custo realizado.
- Saldo orçamentário.
- Contas a pagar.
- Contas a receber.
- Solicitações pendentes.

Gráficos:

- Evolução financeira das obras.
- Comparação entre orçamento e realizado.
- Obras por situação.
- Desempenho mensal.
- Distribuição de custos.

Adicionar:
- Obras recentes.
- Atividades pendentes.
- Alertas.
- Últimas movimentações.

Os valores serão demonstrativos, claramente identificados como dados de demonstração.

## 7.2 Listagem de obras

Apresentar tabela com:

- Código.
- Nome da obra.
- Cliente.
- Responsável.
- Data de início.
- Previsão de término.
- Orçamento.
- Progresso.
- Situação.
- Ações.

Adicionar filtros por cliente, responsável, situação e período.

Criar formulário demonstrativo de cadastro de obra.

## 7.3 Módulo de orçamentos

Este será o módulo prioritário do protótipo.

Criar as telas:

A. Listagem de orçamentos.

B. Cadastro de orçamento.

C. Preenchimento do orçamento.

D. Detalhamento de composição de custos.

E. Resumo e finalização.

### Listagem

Colunas:

- Código.
- Descrição.
- Cliente.
- Obra.
- Responsável.
- Data.
- Valor total.
- Situação.
- Ações.

### Cadastro

Permitir demonstrar:

- Orçamento por cliente.
- Orçamento manual.
- Orçamento baseado em referência técnica.

Campos:

- Cliente.
- Obra.
- Nome do orçamento.
- Descrição.
- Responsável.
- Data de referência.
- Localidade.
- Observações.

### Preenchimento

Criar uma interface organizada em grupos e etapas.

Contemplar:

- Etapas do orçamento.
- Serviços.
- Composições.
- Insumos.
- Materiais.
- Mão de obra.
- Equipamentos.
- Unidades de medida.
- Quantidades.
- Preços unitários.
- Custos totais.
- BDI.
- Encargos, quando aplicáveis.

Permitir adicionar, editar e remover itens utilizando estado local e dados demonstrativos.

### Composição de custos

Criar modal ou painel lateral para apresentar:

- Código da composição.
- Descrição.
- Unidade.
- Insumos associados.
- Coeficientes.
- Preços unitários.
- Custo total.

### Finalização

Apresentar:

- Custo direto.
- BDI.
- Custo indireto, conforme metodologia definida.
- Valor total.
- Curva ABC.
- Distribuição por grupos.
- Gráficos de composição.

Não apresentar cálculos como oficiais quando forem apenas demonstrativos.

## 7.4 Orçamento inteligente

Criar uma interface visual de assistente de IA.

Permitir demonstrar:

- Campo de descrição da obra ou serviço.
- Campo para inserir informações técnicas.
- Sugestões de serviços e composições.
- Análise preliminar do orçamento.
- Alertas de inconsistências.
- Recomendações.

Não conectar à OpenAI nesta etapa.

Não simular que uma IA real foi consultada. Identificar claramente as sugestões como demonstração.

## 7.5 Compras e suprimentos

Criar listagem de solicitações com:

- Número.
- Obra.
- Solicitante.
- Data.
- Itens.
- Prioridade.
- Situação.

Criar formulário demonstrativo de solicitação de materiais.

## 7.6 Clientes e fornecedores

Criar telas de listagem, pesquisa, filtros e formulários demonstrativos.

## 7.7 Demais módulos

Criar páginas iniciais organizadas para os módulos:

- Contratos.
- Planejamento.
- Diário de Obras.
- Medições.
- Financeiro.
- Estoque.
- Equipes.
- Documentos.
- Relatórios.

Quando a funcionalidade ainda não estiver desenvolvida, apresentar estado de módulo em preparação. Não simular funcionalidades completas.

# 8. COMPONENTES REUTILIZÁVEIS

Criar componentes compartilhados para:

- AppLayout.
- Sidebar.
- Topbar.
- PageHeader.
- Breadcrumb.
- StatCard.
- DataTable.
- TableFilters.
- TablePagination.
- StatusBadge.
- FormField.
- CurrencyInput.
- SearchInput.
- Tabs.
- Modal.
- ConfirmDialog.
- Toast.
- EmptyState.
- LoadingState.
- ErrorState.

Os componentes devem ser reutilizados entre os módulos.

Evitar duplicação de código.

# 9. DADOS E SERVIÇOS

Nesta fase, não existe integração obrigatória com a API Laravel.

Utilizar dados demonstrativos centralizados em `src/mocks/`.

Criar interfaces TypeScript para representar os dados.

Criar camada de serviços preparada para futura substituição dos mocks por chamadas HTTP.

Não espalhar dados fictícios dentro dos componentes.

Não utilizar informações pessoais ou empresariais reais.

Não criar uma API paralela no frontend.

# 10. RESPONSIVIDADE

Garantir:

- Desktop: experiência completa.
- Tablet: menu adaptável e tabelas utilizáveis.
- Mobile: navegação compacta e formulários reorganizados.

Em telas pequenas, permitir rolagem horizontal controlada nas tabelas quando necessário.

Não sacrificar a legibilidade para tentar encaixar todas as colunas.

# 11. ACESSIBILIDADE E QUALIDADE

Implementar:

- Navegação por teclado.
- Labels adequados.
- Estados de foco.
- Contraste.
- Feedback visual.
- Tratamento de erros de formulários.
- Tipagem TypeScript rigorosa.
- Componentes sem responsabilidades excessivas.

Executar:

- npm install.
- npm run build.
- Verificação de TypeScript.
- Lint, se configurado.

Corrigir erros encontrados.

# 12. LIMITES DESTA ETAPA

NÃO implementar backend Laravel.

NÃO criar migrations.

NÃO implementar autenticação real.

NÃO conectar banco de dados.

NÃO implementar integração real com IA.

NÃO implementar integração SINAPI ou SUDECAP.

NÃO implementar pagamentos.

NÃO criar dados demonstrativos que possam ser confundidos com informações oficiais.

NÃO alterar decisões de multi-tenancy.

# 13. CRITÉRIOS DE ACEITE

A entrega será considerada concluída quando:

1. A aplicação iniciar localmente.
2. O layout principal estiver implementado.
3. A navegação entre as telas funcionar.
4. O Dashboard estiver visualmente completo.
5. O módulo de Orçamentos possuir os fluxos demonstrativos solicitados.
6. O Design System estiver aplicado.
7. A identidade azul-safira estiver consistente.
8. As telas forem responsivas.
9. Os dados demonstrativos estiverem centralizados.
10. O build for concluído sem erros.

# 14. RELATÓRIO FINAL

Ao terminar, apresentar:

- Telas implementadas.
- Componentes criados.
- Estrutura de diretórios.
- Bibliotecas utilizadas.
- Funcionalidades navegáveis.
- Funcionalidades demonstrativas.
- Resultado do build.
- Pendências.
- Próximas recomendações.

Não iniciar a integração com o backend sem aprovação.

O objetivo é entregar um protótipo visual navegável e profissional que represente a experiência futura do ObraFlow.