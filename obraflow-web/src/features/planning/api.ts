import { env } from '@/lib/env'
import { http } from '@/lib/http'

/** Planejamento da obra (Fase 6A). O servidor calcula tudo (dias úteis, reprogramação, caminho crítico — D-P67). */

export type TaskKind = 'summary' | 'task' | 'milestone'
export type DepType = 'FS' | 'SS' | 'FF' | 'SF'

export interface PlanTask {
  id: number
  parent_id: number | null
  wbs: string
  name: string
  kind: TaskKind
  unit_code: string | null
  quantity: string | null
  start: string
  end: string
  duration_days: number
  calendar_days: number
  actual_start: string | null
  actual_end: string | null
  percent: string
  from_budget: boolean
  critical: boolean
  float: number | null
  late: boolean
  predecessors: { id: number; task_id: number; type: DepType; lag: number }[]
  baseline: { start: string; end: string; delta_days: number } | null
}

export interface PlanData {
  schedule: { uuid: string; origin: 'budget' | 'spreadsheet' | 'blank'; created_at: string; budget: { uuid: string; code: string; version: number } | null } | null
  summary?: { start: string | null; end: string | null; percent: string; tasks: number; critical: number; late: number }
  tasks?: PlanTask[]
  dependencies?: { id: number; predecessor_id: number; successor_id: number; type: DepType; lag_days: number }[]
  baselines?: { number: number; name: string; created_at: string; created_by: { uuid: string; name: string } | null }[]
  compared_baseline?: number | null
  abilities: { edit: boolean }
}

export interface ImportPreview {
  rows: { line: number; data: Record<string, string>; status: 'ok' | 'skip' | 'error'; errors: string[]; warnings: string[]; task: { wbs: string; kind: TaskKind } | null }[]
  summary: { total: number; ok: number; skip: number; error: number }
}

type Body = Record<string, unknown>
const s = (uuid: string) => `/projects/${uuid}/schedule`
const data = <T,>(r: { data: T }) => r.data

export const planningApi = {
  get: (uuid: string, baseline?: number | null) => http<{ data: PlanData }>(s(uuid), { query: baseline ? { baseline } : undefined }).then(data),
  create: (uuid: string, body: Body) => http<{ data: PlanData }>(s(uuid), { method: 'POST', body }).then(data),
  remove: (uuid: string) => http<void>(s(uuid), { method: 'DELETE' }),
  addTask: (uuid: string, body: Body) => http<{ data: PlanData }>(`${s(uuid)}/tasks`, { method: 'POST', body }).then(data),
  updateTask: (uuid: string, id: number, body: Body) => http<{ data: PlanData }>(`${s(uuid)}/tasks/${id}`, { method: 'PUT', body }).then(data),
  moveTask: (uuid: string, id: number, parent_id: number | null, position: number) =>
    http<{ data: PlanData }>(`${s(uuid)}/tasks/${id}/move`, { method: 'POST', body: { parent_id, position } }).then(data),
  removeTask: (uuid: string, id: number) => http<{ data: PlanData }>(`${s(uuid)}/tasks/${id}`, { method: 'DELETE' }).then(data),
  addDependency: (uuid: string, body: Body) => http<{ data: PlanData }>(`${s(uuid)}/dependencies`, { method: 'POST', body }).then(data),
  removeDependency: (uuid: string, id: number) => http<{ data: PlanData }>(`${s(uuid)}/dependencies/${id}`, { method: 'DELETE' }).then(data),
  addBaseline: (uuid: string, name: string) => http<{ data: PlanData }>(`${s(uuid)}/baselines`, { method: 'POST', body: { name } }).then(data),
  importPreview: (uuid: string, file: File) => {
    const form = new FormData()
    form.append('file', file)
    return http<{ data: ImportPreview }>(`${s(uuid)}/import/preview`, { method: 'POST', body: form }).then(data)
  },
  importFile: (uuid: string, file: File) => {
    const form = new FormData()
    form.append('file', file)
    return http(`${s(uuid)}/import`, { method: 'POST', body: form })
  },
  /** Download direto (link): o navegador envia o cookie de sessão da API. */
  templateUrl: (uuid: string) => `${env.apiUrl}${s(uuid)}/import/template`,
}

export const kindLabel: Record<TaskKind, string> = { summary: 'Etapa', task: 'Atividade', milestone: 'Marco' }
export const depLabel: Record<DepType, string> = { FS: 'Término → início', SS: 'Início → início', FF: 'Término → término', SF: 'Início → término' }

// ------------------------------------------------------------------ conversão para o Gantt (SVAR)

/** Nosso tipo de dependência ↔ o do Gantt (e = end, s = start). */
export const toGanttLink: Record<DepType, 'e2s' | 's2s' | 'e2e' | 's2e'> = { FS: 'e2s', SS: 's2s', FF: 'e2e', SF: 's2e' }
export const fromGanttLink: Record<string, DepType> = { e2s: 'FS', s2s: 'SS', e2e: 'FF', s2e: 'SF' }

/** Data local à meia-noite (o Gantt trabalha com Date local). */
export const toDate = (ymd: string) => {
  const [y, m, d] = ymd.split('-').map(Number)
  return new Date(y, m - 1, d)
}
export const toYmd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

/**
 * Atividades no formato do Gantt: o término do Gantt é EXCLUSIVO (dia seguinte ao nosso término inclusivo);
 * marco tem início = fim. Campos próprios (wbs, dias úteis, crítica…) vão junto para as colunas e as barras.
 */
export function toGanttTasks(tasks: PlanTask[]) {
  return tasks.map((t) => {
    const start = toDate(t.start)
    const end = toDate(t.end)
    if (t.kind !== 'milestone') end.setDate(end.getDate() + 1)
    return {
      id: t.id,
      parent: t.parent_id ?? 0,
      text: t.name,
      type: t.kind,
      start,
      end,
      progress: Number(t.percent),
      open: true,
      wbs: t.wbs,
      duration_days: t.duration_days,
      critical: t.critical,
      late: t.late,
      delta: t.baseline?.delta_days ?? null,
    }
  })
}
