import { createBrowserRouter } from 'react-router-dom'
import { AppLayout } from '@/app/layouts/AppLayout'
import { ErrorState } from '@/components/feedback/states'
import { AuthRoot } from '@/features/auth/AuthRoot'
import { GuestOnly, RequireAuth } from '@/features/auth/guards'
import { LoginPage } from '@/features/auth/LoginPage'
import { NoPermissionPage } from '@/features/auth/NoPermissionPage'
import { SelectTenantPage } from '@/features/auth/SelectTenantPage'
import { BudgetDetailPage } from '@/features/budgets/BudgetDetailPage'
import { BudgetsListPage } from '@/features/budgets/BudgetsListPage'
import { SmartBudgetPage } from '@/features/budgets/SmartBudgetPage'
import { RequirePermission } from '@/features/auth/guards'
import { DashboardPage } from '@/features/dashboard/DashboardPage'
import { ModulePage } from '@/features/modules/ModulePage'
import { ProcurementPage } from '@/features/procurement/ProcurementPage'
import { registryRoutes } from '@/features/registry/routes'

const m = (title: string, description: string) => <ModulePage title={title} description={description} />

export const router = createBrowserRouter([
  {
    element: <AuthRoot />,
    errorElement: <ErrorState error={new Error('Página não encontrada ou erro inesperado.')} />,
    children: [
      { element: <GuestOnly />, children: [{ path: '/login', element: <LoginPage /> }] },
      { path: '/organizacao', element: <SelectTenantPage /> },
      {
        element: <RequireAuth />,
        children: [
          {
            path: '/',
            element: <AppLayout />,
            children: [
              { index: true, element: <DashboardPage /> },
              ...registryRoutes,
              {
                element: <RequirePermission permission="budgets.view" />,
                children: [
                  { path: 'orcamentos', element: <BudgetsListPage /> },
                  { path: 'orcamentos/:uuid', element: <BudgetDetailPage /> },
                ],
              },
              { path: 'orcamento-inteligente', element: <SmartBudgetPage /> },
              { path: 'suprimentos', element: <ProcurementPage /> },
              { path: 'contratos', element: m('Contratos', 'Contratos com clientes e fornecedores.') },
              { path: 'planejamento', element: m('Planejamento', 'Cronogramas, dependências e linha de base.') },
              { path: 'diario-de-obras', element: m('Diário de Obras', 'Registro diário de atividades, clima e ocorrências.') },
              { path: 'medicoes', element: m('Medições', 'Medições físicas e contratuais.') },
              { path: 'compras', element: m('Compras', 'Cotações, pedidos de compra e recebimentos.') },
              { path: 'estoque', element: m('Estoque', 'Saldos, movimentações e reservas por depósito.') },
              { path: 'financeiro', element: m('Financeiro', 'Contas a pagar, a receber e fluxo de caixa.') },
              { path: 'documentos', element: m('Documentos', 'Documentos versionados por obra e contrato.') },
              { path: 'relatorios', element: m('Relatórios', 'Relatórios gerenciais e operacionais.') },
              { path: 'sem-permissao', element: <NoPermissionPage /> },
              { path: '*', element: <ErrorState error={new Error('Página não encontrada.')} /> },
            ],
          },
        ],
      },
    ],
  },
])
