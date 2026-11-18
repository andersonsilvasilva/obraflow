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
  /** 6B: custo e venda da atividade/etapa (calculados no servidor). */
  cost?: TaskCost | null
}

/** Tipos de custo do orçamento sintético. */
export type CostKind = 'material' | 'labor' | 'equipment' | 'service' | 'other'
export const COST_KINDS: CostKind[] = ['material', 'labor', 'equipment', 'service', 'other']
export const costKindLabel: Record<CostKind, string> = { material: 'Material', labor: 'Cargo/Função', equipment: 'Equipamento', service: 'Serviço', other: 'Outros' }

export interface TaskCost {
  cost: string
  with_bdi: string
  sale: string
  item_count: number
  kinds: Record<CostKind, { cost: string; sale: string }>
  /** Unitários (por unidade da atividade): só em atividade com recursos. */
  unit_cost: string | null
  unit_sale: string | null
  unit_kinds: Record<CostKind, { cost: string; sale: string }> | null
}

export type ItemSource = 'catalog' | 'sinapi_resource' | 'sinapi_composition' | 'own_composition' | 'budget' | 'manual'
export const itemSourceLabel: Record<ItemSource, string> = {
  catalog: 'Catálogo',
  sinapi_resource: 'Insumo SINAPI',
  sinapi_composition: 'Composição SINAPI',
  own_composition: 'Composição própria',
  budget: 'Orçamento',
  manual: 'Digitado',
}

export interface PlanItem {
  id: number
  task_id: number
  source: ItemSource
  kind: CostKind | 'composition'
  code: string | null
  description: string
  unit_code: string
  quantity_per_unit: string
  unit_cost: string
  costs: Record<CostKind, string>
  bdi_percent: string | null
  discount_percent: string | null
  source_ref: Record<string, unknown> | null
  breakdown: { item_type: string; kind: string | null; code: string; description: string; unit_code: string; coefficient: string; unit_price: string | null; cost: string | null }[] | null
  computed: { quantity_total: string; bdi_percent: string; discount_percent: string; unit_sale: string; cost: string; with_bdi: string; sale: string; kinds: Record<CostKind, { cost: string; sale: string }> }
}

export interface PlanCosts {
  cost: string
  with_bdi: string
  bdi_amount: string
  discount_amount: string
  sale: string
  bdi_percent: string
  discount_percent: string
  by_kind: Record<CostKind, { cost: string; sale: string }>
  item_count: number
}

export interface PlanData {
  schedule: {
    uuid: string
    origin: 'budget' | 'spreadsheet' | 'blank'
    duration_mode: 'calendar' | 'working'
    /** 6B: BDI e desconto do planejamento; regime de encargos da obra (escolhe o valor da composição própria e a base SINAPI). */
    bdi_percent: string
    bdi_components: Record<string, string> | null
    discount_percent: string
    regime: 'with_relief' | 'without_relief' | 'not_applicable' | null
    created_at: string
    budget: { uuid: string; code: string; version: number } | null } | null
  summary?: { start: string | null; end: string | null; percent: string; tasks: number; critical: number; late: number }
  tasks?: PlanTask[]
  dependencies?: { id: number; predecessor_id: number; successor_id: number; type: DepType; lag_days: number }[]
  baselines?: { number: number; name: string; created_at: string; created_by: { uuid: string; name: string } | null }[]
  compared_baseline?: number | null
  items?: PlanItem[]
  costs?: PlanCosts
  abilities: { edit: boolean }
}

export interface ImportPreview {
  rows: { line: number; data: Record<string, string>; status: 'ok' | 'skip' | 'error'; errors: string[]; warnings: string[]; task?: { wbs: string; kind: TaskKind } | null; resource?: { task: string; kind: string } | null }[]
  summary: { total: number; ok: number; skip: number; resources?: number; error: number }
}

type Body = Record<string, unknown>
const s = (uuid: string) => `/projects/${uuid}/schedule`
const data = <T,>(r: { data: T }) => r.data

export const planningApi = {
  get: (uuid: string, baseline?: number | null) => http<{ data: PlanData }>(s(uuid), { query: baseline ? { baseline } : undefined }).then(data),
  create: (uuid: string, body: Body) => http<{ data: PlanData }>(s(uuid), { method: 'POST', body }).then(data),
  update: (uuid: string, body: Body) => http<{ data: PlanData }>(s(uuid), { method: 'PUT', body }).then(data),
  remove: (uuid: string) => http<void>(s(uuid), { method: 'DELETE' }),
  addTask: (uuid: string, body: Body) => http<{ data: PlanData }>(`${s(uuid)}/tasks`, { method: 'POST', body }).then(data),
  updateTask: (uuid: string, id: number, body: Body) => http<{ data: PlanData }>(`${s(uuid)}/tasks/${id}`, { method: 'PUT', body }).then(data),
  moveTask: (uuid: string, id: number, parent_id: number | null, position: number) =>
    http<{ data: PlanData }>(`${s(uuid)}/tasks/${id}/move`, { method: 'POST', body: { parent_id, position } }).then(data),
  removeTask: (uuid: string, id: number) => http<{ data: PlanData }>(`${s(uuid)}/tasks/${id}`, { method: 'DELETE' }).then(data),
  addDependency: (uuid: string, body: Body) => http<{ data: PlanData }>(`${s(uuid)}/dependencies`, { method: 'POST', body }).then(data),
  removeDependency: (uuid: string, id: number) => http<{ data: PlanData }>(`${s(uuid)}/dependencies/${id}`, { method: 'DELETE' }).then(data),
  addBaseline: (uuid: string, name: string) => http<{ data: PlanData }>(`${s(uuid)}/baselines`, { method: 'POST', body: { name } }).then(data),
  // 6B — custos
  pricing: (uuid: string, body: Body) => http<{ data: PlanData }>(`${s(uuid)}/pricing`, { method: 'PUT', body }).then(data),
  addItems: (uuid: string, taskId: number, body: Body) => http<{ data: PlanData }>(`${s(uuid)}/tasks/${taskId}/items`, { method: 'POST', body }).then(data),
  updateItem: (uuid: string, id: number, body: Body) => http<{ data: PlanData }>(`${s(uuid)}/items/${id}`, { method: 'PUT', body }).then(data),
  removeItem: (uuid: string, id: number) => http<{ data: PlanData }>(`${s(uuid)}/items/${id}`, { method: 'DELETE' }).then(data),
  itemsFromBudget: (uuid: string) => http<{ data: PlanData }>(`${s(uuid)}/items/from-budget`, { method: 'POST' }).then(data),
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

/** Número da semana ISO-8601 (a escala semanal mostra "Semana 47", como no Brickup). */
export function isoWeek(date: Date): number {
  const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()))
  const day = d.getUTCDay() || 7
  d.setUTCDate(d.getUTCDate() + 4 - day)
  const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1))
  return Math.ceil(((+d - +yearStart) / 86_400_000 + 1) / 7)
}
