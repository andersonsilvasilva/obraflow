import { http, type Query } from '@/lib/http'
import type { Option, Page } from '@/features/registry/api'

export type ProjectStatus = 'planning' | 'in_progress' | 'paused' | 'delayed' | 'completed' | 'canceled'
export type AccessLevel = 'view' | 'edit' | 'manage'

export interface Address {
  id: number
  kind: 'main' | 'billing' | 'delivery' | 'site'
  street: string
  number: string | null
  complement: string | null
  district: string | null
  city: string
  state: string
  postal_code: string | null
}

export interface Phase {
  id: number
  parent_id: number | null
  name: string
  sort_order: number
  planned_start: string | null
  planned_end: string | null
}

export interface Member {
  id: number
  employee: { uuid: string; name: string } | null
  role: string | null
  started_on: string | null
  ended_on: string | null
}

export interface Project {
  uuid: string
  code: string
  name: string
  description: string | null
  status: ProjectStatus
  progress: number
  contract_value: string | null
  start_date: string | null
  expected_end_date: string | null
  actual_end_date: string | null
  access_level?: AccessLevel | null
  company?: { uuid: string; legal_name: string }
  customer?: { uuid: string; name: string } | null
  technical_manager?: { uuid: string; name: string } | null
  addresses?: Address[]
  phases?: Phase[]
  members?: Member[]
}

export interface StatusHistoryEntry {
  from_status: ProjectStatus | null
  to_status: ProjectStatus
  reason: string | null
  changed_at: string
  changed_by: { uuid: string; name: string } | null
}

export interface AccessEntry {
  user: { uuid: string; name: string; email: string } | null
  access_level: AccessLevel
}

const base = '/projects'
const p = (uuid: string) => `${base}/${uuid}`
const data = <T,>(r: { data: T }) => r.data

export const projectsApi = {
  list: (query?: Query) => http<Page<Project>>(base, { query }),
  get: (uuid: string) => http<{ data: Project }>(p(uuid)).then(data),
  create: (body: Record<string, unknown>) => http<{ data: Project }>(base, { method: 'POST', body }).then(data),
  update: (uuid: string, body: Record<string, unknown>) => http<{ data: Project }>(p(uuid), { method: 'PUT', body }).then(data),
  remove: (uuid: string) => http<void>(p(uuid), { method: 'DELETE' }),
  changeStatus: (uuid: string, status: ProjectStatus, reason: string | null) =>
    http<{ data: Project }>(`${p(uuid)}/status`, { method: 'POST', body: { status, reason } }).then(data),
  history: (uuid: string) => http<{ data: StatusHistoryEntry[] }>(`${p(uuid)}/status-history`).then(data),
  addresses: {
    create: (uuid: string, body: Record<string, unknown>) => http(`${p(uuid)}/addresses`, { method: 'POST', body }),
    update: (uuid: string, id: number, body: Record<string, unknown>) => http(`${p(uuid)}/addresses/${id}`, { method: 'PUT', body }),
    remove: (uuid: string, id: number) => http<void>(`${p(uuid)}/addresses/${id}`, { method: 'DELETE' }),
  },
  phases: {
    create: (uuid: string, body: Record<string, unknown>) => http(`${p(uuid)}/phases`, { method: 'POST', body }),
    update: (uuid: string, id: number, body: Record<string, unknown>) => http(`${p(uuid)}/phases/${id}`, { method: 'PUT', body }),
    remove: (uuid: string, id: number) => http<void>(`${p(uuid)}/phases/${id}`, { method: 'DELETE' }),
  },
  members: {
    create: (uuid: string, body: Record<string, unknown>) => http(`${p(uuid)}/members`, { method: 'POST', body }),
    update: (uuid: string, id: number, body: Record<string, unknown>) => http(`${p(uuid)}/members/${id}`, { method: 'PUT', body }),
    remove: (uuid: string, id: number) => http<void>(`${p(uuid)}/members/${id}`, { method: 'DELETE' }),
  },
  access: {
    list: (uuid: string) => http<{ data: AccessEntry[] }>(`${p(uuid)}/access`).then(data),
    grant: (uuid: string, userUuid: string, level: AccessLevel) =>
      http(`${p(uuid)}/access`, { method: 'POST', body: { user_uuid: userUuid, access_level: level } }),
    revoke: (uuid: string, userUuid: string) => http<void>(`${p(uuid)}/access/${userUuid}`, { method: 'DELETE' }),
  },
}

export const statusOptions: Option[] = [
  { value: 'planning', label: 'Planejamento' },
  { value: 'in_progress', label: 'Em andamento' },
  { value: 'paused', label: 'Paralisada' },
  { value: 'delayed', label: 'Atrasada' },
  { value: 'completed', label: 'Concluída' },
  { value: 'canceled', label: 'Cancelada' },
]

/** Espelho de Project::TRANSITIONS no backend (D-P27) — a API é a autoridade. */
export const transitions: Record<ProjectStatus, ProjectStatus[]> = {
  planning: ['in_progress', 'canceled'],
  in_progress: ['paused', 'delayed', 'completed', 'canceled'],
  paused: ['in_progress', 'canceled'],
  delayed: ['in_progress', 'paused', 'completed', 'canceled'],
  completed: [],
  canceled: [],
}

export const statusTone: Record<ProjectStatus, 'neutral' | 'primary' | 'success' | 'warning' | 'danger'> = {
  planning: 'neutral',
  in_progress: 'primary',
  paused: 'warning',
  delayed: 'danger',
  completed: 'success',
  canceled: 'neutral',
}

export const statusName = (s: string) => statusOptions.find((o) => o.value === s)?.label ?? s

export const levelOptions: Option[] = [
  { value: 'view', label: 'Ver' },
  { value: 'edit', label: 'Editar' },
  { value: 'manage', label: 'Administrar' },
]

const RANK: Record<AccessLevel, number> = { view: 1, edit: 2, manage: 3 }
export const atLeast = (level: AccessLevel | null | undefined, needed: AccessLevel) => !!level && RANK[level] >= RANK[needed]
