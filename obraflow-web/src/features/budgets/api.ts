import { http, type Query } from '@/lib/http'
import type { Option, Page } from '@/features/registry/api'

/** Valores monetários e decimais chegam como TEXTO exato da API (D-P32): nunca recalculados no navegador. */

export type BudgetStatus = 'draft' | 'in_review' | 'approved' | 'rejected' | 'archived'
export type VersionStatus = 'draft' | 'in_review' | 'approved' | 'rejected' | 'superseded'
export type WorkflowAction = 'submit' | 'withdraw' | 'approve' | 'reject' | 'reopen'

export interface Totals {
  direct_cost: string
  indirect_cost: string
  total_cost: string
  bdi_amount: string
  items_discount: string
  items_sale: string
  global_discount_percent: string
  global_discount: string
  final_price: string
  groups: Record<string, { cost: string; sale: string }>
  item_count: number
  calculated_at: string
}

export interface Budget {
  uuid: string
  code: string
  title: string
  mode: 'customer' | 'manual' | 'reference'
  status: BudgetStatus
  state: string | null
  social_charges_regime: 'with_relief' | 'without_relief' | 'not_applicable'
  reference_date: string | null
  notes: string | null
  company?: { uuid: string; legal_name: string }
  project?: { uuid: string; code: string; name: string } | null
  customer?: { uuid: string; name: string } | null
  reference_dataset?: { id: number; competency: string; state: string; charges_regime: string; status: string } | null
  owner?: { uuid: string; name: string } | null
  current_version?: { number: number; label: string | null; status: VersionStatus; locked: boolean; final_price: string | null; direct_cost: string | null }
  versions?: { number: number; label: string | null; status: VersionStatus; locked: boolean; approved_at: string | null; final_price: string | null }[]
  abilities?: { edit: boolean; approve: boolean }
}

export interface Group {
  id: number
  parent_id: number | null
  code: string
  name: string
  sort_order: number
}

export interface Item {
  id: number
  group_id: number
  code: string
  description: string
  unit_code: string
  quantity: string
  unit_cost: string
  bdi_percent: string | null
  discount_percent: string
  total_cost: string
  total_sale: string
  source: 'official' | 'custom' | 'manual'
  source_ref: { source: string; competency: string; state: string; regime: string; type: string; code: string } | null
  sort_order: number
}

export interface Bdi {
  id: number
  scope: 'global' | 'group' | 'item'
  group_id: number | null
  item_id: number | null
  method: 'informed' | 'tcu'
  components: Record<string, string> | null
  percent: string
}

export interface IndirectCost {
  id: number
  description: string
  method: 'fixed' | 'percent_of_direct'
  percent: string | null
  amount: string | null
}

export interface Version {
  number: number
  label: string | null
  status: VersionStatus
  locked: boolean
  social_charges_regime: string
  discount_percent: string
  approved_at: string | null
  totals: Totals
  groups: Group[]
  items: Item[]
  bdi: Bdi[]
  indirect_costs: IndirectCost[]
}

export interface AbcRow {
  code: string
  description: string
  unit: string
  kind?: string | null
  quantity: string
  unit_value: string
  total: string
  percent: string
  cumulative_percent: string
  class: 'A' | 'B' | 'C'
}

export interface History {
  status: { version: number; from: string | null; to: string; reason: string | null; at: string; by: { uuid: string; name: string } | null }[]
  decisions: { version: number; decision: 'approved' | 'rejected'; comment: string | null; totals_hash: string; at: string; by: { uuid: string; name: string } | null }[]
}

export interface Dataset {
  id: number
  source: { code: string; name: string } | null
  competency: string
  state: string
  location: string | null
  charges_regime: 'with_relief' | 'without_relief'
  status: string
}

export interface RefResult {
  code: string
  description: string
  unit: string
  total_cost?: string
  price?: string | null
  kind?: string | null
}

const b = (uuid: string) => `/budgets/${uuid}`
const v = (uuid: string, n: number) => `${b(uuid)}/versions/${n}`
const data = <T,>(r: { data: T }) => r.data
type Body = Record<string, unknown>

export const budgetsApi = {
  list: (query?: Query) => http<Page<Budget>>('/budgets', { query }),
  get: (uuid: string) => http<{ data: Budget }>(b(uuid)).then(data),
  create: (body: Body) => http<{ data: Budget }>('/budgets', { method: 'POST', body }).then(data),
  update: (uuid: string, body: Body) => http<{ data: Budget }>(b(uuid), { method: 'PUT', body }).then(data),
  remove: (uuid: string) => http<void>(b(uuid), { method: 'DELETE' }),
  history: (uuid: string) => http<{ data: History }>(`${b(uuid)}/history`).then(data),
  workflow: (uuid: string, action: WorkflowAction, comment?: string) => http(`${b(uuid)}/workflow/${action}`, { method: 'POST', body: { comment } }),
  newVersion: (uuid: string, label?: string) => http(`${b(uuid)}/versions`, { method: 'POST', body: { label } }),
  version: (uuid: string, n: number) => http<{ data: Version }>(v(uuid, n)).then(data),
  updateVersion: (uuid: string, n: number, body: Body) => http(v(uuid, n), { method: 'PUT', body }),
  abc: (uuid: string, n: number, type: 'services' | 'resources') => http<{ data: AbcRow[] }>(`${v(uuid, n)}/abc`, { query: { type } }).then(data),
  groups: {
    create: (uuid: string, n: number, body: Body) => http(`${v(uuid, n)}/groups`, { method: 'POST', body }),
    update: (uuid: string, n: number, id: number, body: Body) => http(`${v(uuid, n)}/groups/${id}`, { method: 'PUT', body }),
    remove: (uuid: string, n: number, id: number) => http<void>(`${v(uuid, n)}/groups/${id}`, { method: 'DELETE' }),
  },
  items: {
    create: (uuid: string, n: number, body: Body) => http(`${v(uuid, n)}/items`, { method: 'POST', body }),
    fromReference: (uuid: string, n: number, body: Body) => http(`${v(uuid, n)}/items/reference`, { method: 'POST', body }),
    update: (uuid: string, n: number, id: number, body: Body) => http(`${v(uuid, n)}/items/${id}`, { method: 'PUT', body }),
    remove: (uuid: string, n: number, id: number) => http<void>(`${v(uuid, n)}/items/${id}`, { method: 'DELETE' }),
    composition: (uuid: string, n: number, id: number) =>
      http<{ data: { composition: { code: string; description: string; unit_code: string; unit_cost: string; source_snapshot: Record<string, string>; items: { item_type: string; kind: string | null; code: string; description: string; unit_code: string; coefficient: string; unit_price: string | null; cost: string | null }[] } | null; resources: { kind: string | null; code: string; description: string; unit_code: string; coefficient: string; unit_price: string | null }[] } }>(
        `${v(uuid, n)}/items/${id}/composition`,
      ).then(data),
  },
  bdi: (uuid: string, n: number, body: Body) => http(`${v(uuid, n)}/bdi`, { method: 'PUT', body }),
  indirect: {
    create: (uuid: string, n: number, body: Body) => http(`${v(uuid, n)}/indirect-costs`, { method: 'POST', body }),
    update: (uuid: string, n: number, id: number, body: Body) => http(`${v(uuid, n)}/indirect-costs/${id}`, { method: 'PUT', body }),
    remove: (uuid: string, n: number, id: number) => http<void>(`${v(uuid, n)}/indirect-costs/${id}`, { method: 'DELETE' }),
  },
  references: {
    datasets: () => http<{ data: Dataset[] }>('/references/datasets').then(data),
    search: (dataset: number, type: 'compositions' | 'resources', q: string) =>
      http<Page<RefResult>>(`/references/datasets/${dataset}/${type}`, { query: { q, per_page: 15 } }),
  },
}

export const budgetStatusLabel: Record<string, string> = {
  draft: 'Rascunho',
  in_review: 'Em revisão',
  approved: 'Aprovado',
  rejected: 'Rejeitado',
  archived: 'Arquivado',
  superseded: 'Substituída',
}
export const budgetStatusTone: Record<string, 'neutral' | 'primary' | 'success' | 'warning' | 'danger'> = {
  draft: 'neutral',
  in_review: 'primary',
  approved: 'success',
  rejected: 'danger',
  archived: 'neutral',
  superseded: 'neutral',
}
export const budgetStatusOptions: Option[] = ['draft', 'in_review', 'approved', 'rejected'].map((s) => ({ value: s, label: budgetStatusLabel[s] }))

export const regimeLabel: Record<string, string> = {
  with_relief: 'Desonerado',
  without_relief: 'Não desonerado',
  not_applicable: 'Não se aplica',
}

export const modeOptions: Option[] = [
  { value: 'reference', label: 'Base de referência (SINAPI)' },
  { value: 'manual', label: 'Manual' },
  { value: 'customer', label: 'Do cliente' },
]

/** Ações do fluxo disponíveis para o status da versão corrente (espelho de BudgetWorkflow — a API decide). */
export function workflowActions(status: VersionStatus | undefined, abilities?: { edit: boolean; approve: boolean }): WorkflowAction[] {
  if (!status || !abilities) return []
  const map: Record<string, WorkflowAction[]> = {
    draft: abilities.edit ? ['submit'] : [],
    in_review: [...(abilities.edit ? (['withdraw'] as const) : []), ...(abilities.approve ? (['approve', 'reject'] as const) : [])],
    rejected: abilities.edit ? ['reopen'] : [],
  }
  return map[status] ?? []
}
