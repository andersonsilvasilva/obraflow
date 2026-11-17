import { http, type Query } from '@/lib/http'
import type { Option, Page } from '@/features/registry/api'

export type ResourceKind = 'material' | 'labor' | 'equipment' | 'service'

export interface CatalogResource {
  uuid: string
  kind: ResourceKind
  code: string | null
  name: string
  brand: string | null
  model: string | null
  price: string | null
  price_date: string | null
  notes: string | null
  status: 'active' | 'inactive'
  unit?: { id: number; code: string; name: string } | null
  group?: { id: number; name: string; parent_id: number | null; parent_name: string | null } | null
  main_supplier?: { uuid: string; legal_name: string; trade_name: string | null } | null
  job_title?: { id: number; name: string } | null
  equipment?: { uuid: string; name: string; code: string | null } | null
  labor_costs?: { id: number; effective_date: string; hourly_cost: string }[]
}

export interface LaborCost {
  id: number
  effective_date: string
  base_salary: string
  extra_hour: string | null
  charges_percent: string
  admin_percent: string
  monthly_hours: string
  labor_type: 'direct' | 'indirect' | null
  contract_type: 'monthly' | 'hourly' | 'piecework' | 'daily' | null
  hourly_cost: string
  current: boolean
}

export interface CatalogGroup {
  id: number
  kind: ResourceKind
  parent_id: number | null
  name: string
}

export interface ImportRow {
  line: number
  data: Record<string, string>
  status: 'ok' | 'skip' | 'error'
  errors: string[]
  warnings: string[]
  actions: string[]
}

export interface ImportPreview {
  rows: ImportRow[]
  summary: { total: number; ok: number; skip: number; error: number }
}

const data = <T,>(r: { data: T }) => r.data
const fileForm = (kind: string, file: File) => {
  const f = new FormData()
  f.append('kind', kind)
  f.append('file', file)
  return f
}

export const catalogApi = {
  list: (query?: Query) => http<Page<CatalogResource>>('/resources', { query }),
  get: (uuid: string) => http<{ data: CatalogResource }>(`/resources/${uuid}`).then(data),
  create: (body: Record<string, unknown>) => http<{ data: CatalogResource }>('/resources', { method: 'POST', body }).then(data),
  update: (uuid: string, body: Record<string, unknown>) => http<{ data: CatalogResource }>(`/resources/${uuid}`, { method: 'PUT', body }).then(data),
  remove: (uuid: string) => http<void>(`/resources/${uuid}`, { method: 'DELETE' }),
  laborCosts: (uuid: string) => http<{ data: LaborCost[] }>(`/resources/${uuid}/labor-costs`).then(data),
  addLaborCost: (uuid: string, body: Record<string, unknown>) => http<{ data: LaborCost }>(`/resources/${uuid}/labor-costs`, { method: 'POST', body }),
  removeLaborCost: (uuid: string, id: number) => http<void>(`/resources/${uuid}/labor-costs/${id}`, { method: 'DELETE' }),
  importPreview: (kind: string, file: File) => http<{ data: ImportPreview }>('/resources/import/preview', { method: 'POST', body: fileForm(kind, file) }).then(data),
  import: (kind: string, file: File) =>
    http<{ data: ImportPreview['summary'] & { created: number } }>('/resources/import', { method: 'POST', body: fileForm(kind, file) }).then(data),
  groups: {
    all: (kind?: ResourceKind) => http<{ data: CatalogGroup[] }>('/catalog-groups', { query: { kind } }).then(data),
    create: (body: Record<string, unknown>) => http<{ data: CatalogGroup }>('/catalog-groups', { method: 'POST', body }).then(data),
    update: (id: number, body: Record<string, unknown>) => http<{ data: CatalogGroup }>(`/catalog-groups/${id}`, { method: 'PUT', body }).then(data),
    remove: (id: number) => http<void>(`/catalog-groups/${id}`, { method: 'DELETE' }),
  },
}

export const kindLabel: Record<ResourceKind, string> = {
  material: 'Materiais',
  labor: 'Mão de obra',
  equipment: 'Equipamentos (custo)',
  service: 'Serviços',
}
export const kindSingular: Record<ResourceKind, string> = {
  material: 'material',
  labor: 'mão de obra',
  equipment: 'equipamento',
  service: 'serviço',
}
export const catalogKindOptions: Option[] = [
  { value: 'material', label: 'Material' },
  { value: 'labor', label: 'Mão de obra' },
  { value: 'service', label: 'Serviço' },
]

/** Prévia do custo/hora do balanço (D-P39) — só exibição; o valor gravado é o calculado pela API. */
export function laborPreview(salary: string, charges: string, admin: string, hours: string): number | null {
  const n = (v: string) => Number(v.replace(',', '.'))
  if (!salary || !hours || n(hours) <= 0) return null
  return (n(salary) * (1 + n(charges || '0') / 100) * (1 + n(admin || '0') / 100)) / n(hours)
}
