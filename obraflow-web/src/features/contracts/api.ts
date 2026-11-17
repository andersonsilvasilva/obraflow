import { http, type Query } from '@/lib/http'
import type { Option, Page } from '@/features/registry/api'

/** Contratos da obra (Fase 5A). Valores chegam como TEXTO exato da API (D-P32): nunca recalculados no navegador. */

export type ContractStatus = 'draft' | 'active' | 'suspended' | 'completed' | 'canceled'
export type ContractAction = 'activate' | 'suspend' | 'resume' | 'complete' | 'cancel'

export interface Contract {
  uuid: string
  code: string
  kind: 'customer' | 'supplier'
  status: ContractStatus
  object: string
  original_value: string
  current_value: string
  starts_at: string
  original_end_date: string | null
  current_end_date: string | null
  signed_at: string | null
  retention_percent: string
  readjustment_rule: string | null
  notes: string | null
  company?: { uuid: string; legal_name: string }
  project?: { uuid: string; code: string; name: string }
  customer?: { uuid: string; name: string } | null
  supplier?: { uuid: string; name: string } | null
  budget?: { uuid: string; code: string; version: number } | null
  items_count?: number
  documents_count?: number
  abilities?: { edit: boolean }
}

export interface ContractItem {
  id: number
  code: string | null
  description: string
  unit_code: string
  original_quantity: string
  quantity: string
  unit_price: string
  total: string
  sort_order: number
  from_budget: boolean
  addendum: number | null
}

export interface AddendumLine {
  id: number
  line_type: 'change' | 'new'
  contract_item_id: number | null
  quantity_delta: string
  code: string | null
  description: string | null
  unit_code: string | null
  unit_price: string
}

export interface Addendum {
  id: number
  number: number
  kind: 'value' | 'term' | 'scope' | 'mixed'
  status: 'draft' | 'approved' | 'canceled'
  description: string
  value_delta: string
  new_end_date: string | null
  signed_at: string | null
  approved_at: string | null
  approved_by: { uuid: string; name: string } | null
  created_by: { uuid: string; name: string } | null
  over_25_percent: boolean | null
  lines: AddendumLine[]
}

export interface GenerateResult {
  items: number
  contract_total: string
  budget_final_price: string
  difference: string
}

export interface LinkedDocument {
  uuid: string
  title: string
  current_version: { version_number: number; original_name: string; size_bytes: number; created_at: string } | null
  created_at: string
}

type Body = Record<string, unknown>
const c = (uuid: string) => `/contracts/${uuid}`
const data = <T,>(r: { data: T }) => r.data

export const contractsApi = {
  list: (query?: Query) => http<Page<Contract>>('/contracts', { query }),
  get: (uuid: string) => http<{ data: Contract }>(c(uuid)).then(data),
  create: (body: Body) => http<{ data: Contract }>('/contracts', { method: 'POST', body }).then(data),
  update: (uuid: string, body: Body) => http<{ data: Contract }>(c(uuid), { method: 'PUT', body }).then(data),
  workflow: (uuid: string, action: ContractAction, body: Body = {}) => http<{ data: Contract }>(`${c(uuid)}/workflow/${action}`, { method: 'POST', body }).then(data),
  history: (uuid: string) =>
    http<{ data: { from: string | null; to: string; reason: string | null; at: string; by: { uuid: string; name: string } | null }[] }>(`${c(uuid)}/history`).then(data),
  items: {
    list: (uuid: string) => http<{ data: ContractItem[] }>(`${c(uuid)}/items`).then(data),
    create: (uuid: string, body: Body) => http(`${c(uuid)}/items`, { method: 'POST', body }),
    update: (uuid: string, id: number, body: Body) => http(`${c(uuid)}/items/${id}`, { method: 'PUT', body }),
    remove: (uuid: string, id: number) => http<void>(`${c(uuid)}/items/${id}`, { method: 'DELETE' }),
    fromBudget: (uuid: string, budget_uuid: string, version: number) =>
      http<{ data: GenerateResult }>(`${c(uuid)}/items/from-budget`, { method: 'POST', body: { budget_uuid, version } }).then(data),
  },
  addenda: {
    list: (uuid: string) => http<{ data: Addendum[] }>(`${c(uuid)}/addenda`).then(data),
    create: (uuid: string, body: Body) => http<{ data: Addendum }>(`${c(uuid)}/addenda`, { method: 'POST', body }).then(data),
    update: (uuid: string, n: number, body: Body) => http(`${c(uuid)}/addenda/${n}`, { method: 'PUT', body }),
    approve: (uuid: string, n: number, signed_at: string) => http<{ data: Addendum }>(`${c(uuid)}/addenda/${n}/approve`, { method: 'POST', body: { signed_at } }).then(data),
    cancel: (uuid: string, n: number, reason?: string) => http(`${c(uuid)}/addenda/${n}/cancel`, { method: 'POST', body: { reason } }),
    addLine: (uuid: string, n: number, body: Body) => http(`${c(uuid)}/addenda/${n}/lines`, { method: 'POST', body }),
    removeLine: (uuid: string, n: number, id: number) => http<void>(`${c(uuid)}/addenda/${n}/lines/${id}`, { method: 'DELETE' }),
  },
}

/** Documentos ligados a um contrato ou aditivo (D-P55): cria o documento com o arquivo e vincula. */
export const attachmentsApi = {
  list: (type: 'contract' | 'contract_addendum', key: string) =>
    http<Page<LinkedDocument>>('/documents', { query: { linkable_type: type, linkable_key: key, per_page: 100 } }).then((p) => p.data),
  upload: async (type: 'contract' | 'contract_addendum', key: string, title: string, file: File) => {
    const form = new FormData()
    form.append('title', title)
    form.append('file', file)
    const doc = await http<{ data: { uuid: string } }>('/documents', { method: 'POST', body: form }).then(data)
    await http(`/documents/${doc.uuid}/links`, { method: 'POST', body: { linkable_type: type, linkable_key: key } })
    return doc
  },
  url: (uuid: string, version: number) => http<{ data: { url: string } }>(`/documents/${uuid}/versions/${version}/url`, { method: 'POST' }).then(data),
}

export const contractStatusLabel: Record<ContractStatus, string> = {
  draft: 'Rascunho',
  active: 'Ativo',
  suspended: 'Suspenso',
  completed: 'Concluído',
  canceled: 'Cancelado',
}
export const contractStatusTone: Record<ContractStatus, 'neutral' | 'primary' | 'success' | 'warning' | 'danger'> = {
  draft: 'neutral',
  active: 'success',
  suspended: 'warning',
  completed: 'primary',
  canceled: 'danger',
}
export const kindOptions: Option[] = [
  { value: 'customer', label: 'Com cliente (receita)' },
  { value: 'supplier', label: 'Com fornecedor/empreiteiro (despesa)' },
]
export const addendumKindLabel: Record<Addendum['kind'], string> = { value: 'Valor', term: 'Prazo', scope: 'Escopo', mixed: 'Misto' }
export const addendumStatusLabel: Record<Addendum['status'], string> = { draft: 'Rascunho', approved: 'Aprovado', canceled: 'Cancelado' }

/** Ações do ciclo de vida disponíveis por status (espelho de ContractWorkflow — a API decide). */
export function contractActions(status: ContractStatus): ContractAction[] {
  const map: Record<ContractStatus, ContractAction[]> = {
    draft: ['activate', 'cancel'],
    active: ['suspend', 'complete', 'cancel'],
    suspended: ['resume', 'complete', 'cancel'],
    completed: [],
    canceled: [],
  }
  return map[status]
}

export const partyName = (k: Pick<Contract, 'kind' | 'customer' | 'supplier'>) => (k.kind === 'customer' ? k.customer?.name : k.supplier?.name) ?? '—'
