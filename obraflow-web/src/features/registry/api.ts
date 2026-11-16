import { http, type Query } from '@/lib/http'

/** Lista paginada no formato da API ({data, meta}). */
export interface Page<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number }
}

export interface Option {
  value: string
  label: string
}

export type Status = 'active' | 'inactive'

// ------------------------------------------------------------------ Fase 2
export interface Company {
  uuid: string
  legal_name: string
  trade_name: string | null
  document_number: string
  state_registration: string | null
  municipal_registration: string | null
  email: string | null
  phone: string | null
  status: Status
}

export interface Party {
  uuid: string
  company_uuid?: string
  type: 'person' | 'company'
  document_number: string | null
  name?: string
  legal_name?: string
  trade_name?: string | null
  email: string | null
  phone: string | null
  status: Status
}

export interface Unit {
  id: number
  code: string
  name: string
  status: Status
}

// ------------------------------------------------------------------ Fase 3
export interface CatalogItem {
  id: number
  name: string
  status: Status
}

export interface ResourceGroup {
  id: number
  kind: 'equipment'
  parent_id: number | null
  name: string
}

export interface Certification {
  id: number
  name: string
  issued_at: string | null
  expires_at: string | null
  expired: boolean
  document: { uuid: string; title: string } | null
}

export interface Employee {
  uuid: string
  name: string
  registration_number: string | null
  document_number: string | null
  phone: string | null
  email: string | null
  labor_type: 'direct' | 'indirect' | null
  contract_type: 'monthly' | 'hourly' | 'piecework' | 'daily' | null
  hired_at: string | null
  terminated_at: string | null
  status: Status
  company?: { uuid: string; legal_name: string }
  job_title?: { id: number; name: string } | null
  department?: { id: number; name: string } | null
  supervisor?: { uuid: string; name: string } | null
  user?: { uuid: string; name: string } | null
  certifications?: Certification[]
}

export interface Equipment {
  uuid: string
  code: string | null
  name: string
  brand: string | null
  model: string | null
  kind: string | null
  condition: string | null
  ownership: 'owned' | 'rented'
  status: 'available' | 'in_use' | 'maintenance' | 'retired'
  company?: { uuid: string; legal_name: string }
  group?: { id: number; name: string; parent_id: number | null } | null
  unit?: { id: number; code: string; name: string } | null
  main_supplier?: { uuid: string; legal_name: string; trade_name: string | null } | null
}

/** CRUD padrão de um recurso da API (`base` = caminho da coleção; `key` = uuid ou id). */
export function resource<T>(base: string) {
  return {
    list: (query?: Query) => http<Page<T>>(base, { query }),
    all: (query?: Query) => http<{ data: T[] }>(base, { query }).then((r) => r.data),
    get: (key: string | number) => http<{ data: T }>(`${base}/${key}`).then((r) => r.data),
    create: (body: Record<string, unknown>) => http<{ data: T }>(base, { method: 'POST', body }).then((r) => r.data),
    update: (key: string | number, body: Record<string, unknown>) => http<{ data: T }>(`${base}/${key}`, { method: 'PUT', body }).then((r) => r.data),
    remove: (key: string | number) => http<void>(`${base}/${key}`, { method: 'DELETE' }),
  }
}

export const api = {
  companies: resource<Company>('/companies'),
  customers: resource<Party>('/customers'),
  suppliers: resource<Party>('/suppliers'),
  units: resource<Unit>('/units'),
  jobTitles: resource<CatalogItem>('/job-titles'),
  departments: resource<CatalogItem>('/departments'),
  resourceGroups: resource<ResourceGroup>('/resource-groups'),
  employees: resource<Employee>('/employees'),
  equipment: resource<Equipment>('/equipment'),
  certifications: (employeeUuid: string) => resource<Certification>(`/employees/${employeeUuid}/certifications`),
}

export const statusOptions: Option[] = [
  { value: 'active', label: 'Ativo' },
  { value: 'inactive', label: 'Inativo' },
]

export const statusLabel = (s: string) => statusOptions.find((o) => o.value === s)?.label ?? s
