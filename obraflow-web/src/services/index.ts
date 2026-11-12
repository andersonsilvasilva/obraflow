import { budgets, compositions, customers, dashboard, initialBudgetGroups, projects, purchaseRequests, suppliers } from '@/mocks/data'
import { fetchResource } from './api'

export const dashboardService = { get: () => fetchResource('/dashboard', () => dashboard) }
export const projectsService = { list: () => fetchResource('/projects', () => projects) }
export const budgetsService = {
  list: () => fetchResource('/budgets', () => budgets),
  groups: (id: string) => fetchResource(`/budgets/${id}/groups`, () => initialBudgetGroups),
  compositions: () => fetchResource('/reference/compositions', () => compositions),
}
export const procurementService = { list: () => fetchResource('/purchase-requests', () => purchaseRequests) }
export const partiesService = {
  customers: () => fetchResource('/customers', () => customers),
  suppliers: () => fetchResource('/suppliers', () => suppliers),
}
