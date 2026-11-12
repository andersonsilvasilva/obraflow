import { createBrowserRouter, Navigate } from 'react-router-dom'
import { AppLayout } from '@/app/layouts/AppLayout'
import { ErrorState } from '@/components/feedback/states'
import { BudgetCreatePage } from '@/features/budgets/BudgetCreatePage'
import { BudgetEditorPage } from '@/features/budgets/BudgetEditorPage'
import { BudgetFinalizationPage } from '@/features/budgets/BudgetFinalizationPage'
import { BudgetsListPage } from '@/features/budgets/BudgetsListPage'
import { BudgetWorkspace } from '@/features/budgets/BudgetWorkspace'
import { SmartBudgetPage } from '@/features/budgets/SmartBudgetPage'
import { DashboardPage } from '@/features/dashboard/DashboardPage'
import { ModulePage } from '@/features/modules/ModulePage'
import { PartiesPage } from '@/features/parties/PartiesPage'
import { ProcurementPage } from '@/features/procurement/ProcurementPage'
import { ProjectsPage } from '@/features/projects/ProjectsPage'

const m = (title: string, description: string) => <ModulePage title={title} description={description} />

export const router = createBrowserRouter([
  {
    path: '/',
    element: <AppLayout />,
    errorElement: <ErrorState error={new Error('Página não encontrada ou erro inesperado.')} />,
    children: [
      { index: true, element: <DashboardPage /> },
      { path: 'empresas', element: m('Empresas', 'Empresas e filiais do tenant.') },
      { path: 'clientes', element: <PartiesPage kind="customers" /> },
      { path: 'fornecedores', element: <PartiesPage kind="suppliers" /> },
      { path: 'obras', element: <ProjectsPage /> },
      { path: 'contratos', element: m('Contratos', 'Contratos com clientes e fornecedores.') },
      {
        path: 'orcamentos',
        children: [
          { index: true, element: <BudgetsListPage /> },
          { path: 'novo', element: <BudgetCreatePage /> },
          { path: 'inteligente', element: <SmartBudgetPage /> },
          {
            path: ':id',
            element: <BudgetWorkspace />,
            children: [
              { index: true, element: <Navigate to="preencher" replace /> },
              { path: 'preencher', element: <BudgetEditorPage /> },
              { path: 'finalizacao', element: <BudgetFinalizationPage /> },
            ],
          },
        ],
      },
      { path: 'planejamento', element: m('Planejamento', 'Cronogramas, dependências e linha de base.') },
      { path: 'diario-de-obras', element: m('Diário de Obras', 'Registro diário de atividades, clima e ocorrências.') },
      { path: 'medicoes', element: m('Medições', 'Medições físicas e contratuais.') },
      { path: 'compras', element: m('Compras', 'Cotações, pedidos de compra e recebimentos.') },
      { path: 'suprimentos', element: <ProcurementPage /> },
      { path: 'estoque', element: m('Estoque', 'Saldos, movimentações e reservas por depósito.') },
      { path: 'equipes', element: m('Equipes', 'Equipes, alocações e equipamentos.') },
      { path: 'financeiro', element: m('Financeiro', 'Contas a pagar, a receber e fluxo de caixa.') },
      { path: 'documentos', element: m('Documentos', 'Documentos versionados por obra e contrato.') },
      { path: 'relatorios', element: m('Relatórios', 'Relatórios gerenciais e operacionais.') },
      { path: 'configuracoes/minha-conta', element: m('Minha conta', 'Dados pessoais e preferências.') },
      { path: 'configuracoes/usuarios', element: m('Usuários e permissões', 'Gestão de acessos e papéis.') },
      { path: 'configuracoes/empresa', element: m('Configurações da empresa', 'Parâmetros da organização.') },
      { path: '*', element: <ErrorState error={new Error('Página não encontrada.')} /> },
    ],
  },
])
