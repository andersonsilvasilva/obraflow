import { dashboard, purchaseRequests } from '@/mocks/data'
import { fetchResource } from './api'

export const dashboardService = { get: () => fetchResource('/dashboard', () => dashboard) }
export const procurementService = { list: () => fetchResource('/purchase-requests', () => purchaseRequests) }
