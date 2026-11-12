export type ProjectStatus = 'planning' | 'in_progress' | 'completed' | 'delayed' | 'paused'

export interface Project {
  id: string
  code: string
  name: string
  customer: string
  manager: string
  startDate: string
  expectedEndDate: string
  budget: number
  progress: number
  status: ProjectStatus
}

export type BudgetStatus = 'draft' | 'in_review' | 'approved' | 'rejected'
export type BudgetMode = 'customer' | 'manual' | 'reference'

export interface Budget {
  id: string
  code: string
  description: string
  customer: string
  project: string
  owner: string
  date: string
  total: number
  status: BudgetStatus
}

export type ResourceType = 'service' | 'material' | 'labor' | 'equipment'

export interface CompositionInput {
  id: string
  description: string
  type: ResourceType
  unit: string
  coefficient: number
  unitPrice: number
}

export interface Composition {
  id: string
  code: string
  description: string
  unit: string
  inputs: CompositionInput[]
}

export interface BudgetItem {
  id: string
  code: string
  description: string
  type: ResourceType
  unit: string
  quantity: number
  unitPrice: number
  compositionId?: string
}

export interface BudgetGroup {
  id: string
  name: string
  items: BudgetItem[]
}

export type RequestPriority = 'low' | 'medium' | 'high' | 'urgent'
export type RequestStatus = 'draft' | 'pending' | 'approved' | 'quoting' | 'rejected'

export interface PurchaseRequest {
  id: string
  number: string
  project: string
  requester: string
  date: string
  itemsCount: number
  priority: RequestPriority
  status: RequestStatus
}

export interface Party {
  id: string
  name: string
  document: string
  email: string
  phone: string
  city: string
  status: 'active' | 'inactive'
}

export interface DashboardData {
  kpis: {
    totalProjects: number
    inProgress: number
    completed: number
    delayed: number
    totalBudget: number
    realizedCost: number
    payable: number
    receivable: number
    pendingRequests: number
  }
  financialEvolution: { month: string; planned: number; realized: number }[]
  budgetVsActual: { project: string; budget: number; actual: number }[]
  byStatus: { name: string; value: number }[]
  monthlyPerformance: { month: string; progress: number }[]
  costDistribution: { name: string; value: number }[]
  recentProjects: Pick<Project, 'id' | 'code' | 'name' | 'progress' | 'status'>[]
  pendingTasks: { id: string; title: string; due: string }[]
  alerts: { id: string; tone: 'warning' | 'danger' | 'primary'; text: string }[]
  movements: { id: string; text: string; date: string }[]
}
