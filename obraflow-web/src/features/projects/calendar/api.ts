import { http } from '@/lib/http'

/** Calendário (exceções) e horários da obra — CALENDARIO_OBRA_MODELO.md. */

export type RepeatUnit = 'day' | 'week' | 'fortnight' | 'month' | 'year'

export interface CalendarException {
  uuid: string
  description: string
  date: string
  kind: 'single' | 'recurring'
  repeat_every: number | null
  repeat_unit: RepeatUnit | null
  repeat_until: string | null
  created_by: { uuid: string; name: string } | null
  created_at: string | null
}

export interface CalendarRange {
  from: string
  to: string
  days: { date: string; description: string; exception: string; kind: 'single' | 'recurring' }[]
  non_working_weekdays: number[]
}

export interface ScheduleDay {
  weekday: number
  start_1: string | null
  end_1: string | null
  start_2: string | null
  end_2: string | null
  hours: string
  defined: boolean
}

export interface Schedule {
  /** project = horário próprio da obra; company = herdado da empresa; none = nenhum definido */
  source: 'project' | 'company' | 'none'
  days: ScheduleDay[]
  weekly_hours: string
}

export type ExceptionInput = Pick<CalendarException, 'description' | 'date' | 'kind' | 'repeat_every' | 'repeat_unit' | 'repeat_until'>
export type ScheduleInput = Pick<ScheduleDay, 'start_1' | 'end_1' | 'start_2' | 'end_2'>[]

const p = (uuid: string) => `/projects/${uuid}`
const data = <T,>(r: { data: T }) => r.data

export const calendarApi = {
  range: (uuid: string, from: string, to: string) => http<{ data: CalendarRange }>(`${p(uuid)}/calendar`, { query: { from, to } }).then(data),
  exceptions: (uuid: string) => http<{ data: CalendarException[] }>(`${p(uuid)}/calendar/exceptions`).then(data),
  create: (uuid: string, body: ExceptionInput) => http(`${p(uuid)}/calendar/exceptions`, { method: 'POST', body }),
  update: (uuid: string, id: string, body: ExceptionInput) => http(`${p(uuid)}/calendar/exceptions/${id}`, { method: 'PUT', body }),
  remove: (uuid: string, id: string) => http<void>(`${p(uuid)}/calendar/exceptions/${id}`, { method: 'DELETE' }),
  importWeekends: (uuid: string) => http<{ data: { created: number } }>(`${p(uuid)}/calendar/import-weekends`, { method: 'POST' }).then(data),
  importHolidays: (uuid: string, year: number) => http<{ data: { created: number } }>(`${p(uuid)}/calendar/import-holidays`, { method: 'POST', body: { year } }).then(data),
  schedule: (uuid: string) => http<{ data: Schedule }>(`${p(uuid)}/work-schedule`).then(data),
  saveSchedule: (uuid: string, days: ScheduleInput) => http<{ data: Schedule }>(`${p(uuid)}/work-schedule`, { method: 'PUT', body: { days } }).then(data),
  resetSchedule: (uuid: string) => http<{ data: Schedule }>(`${p(uuid)}/work-schedule`, { method: 'DELETE' }).then(data),
  companySchedule: (uuid: string) => http<{ data: Schedule }>(`/companies/${uuid}/work-schedule`).then(data),
  saveCompanySchedule: (uuid: string, days: ScheduleInput) => http<{ data: Schedule }>(`/companies/${uuid}/work-schedule`, { method: 'PUT', body: { days } }).then(data),
}

export const WEEKDAYS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado']

export const unitLabel: Record<RepeatUnit, [string, string]> = {
  day: ['dia', 'dias'],
  week: ['semana', 'semanas'],
  fortnight: ['quinzena', 'quinzenas'],
  month: ['mês', 'meses'],
  year: ['ano', 'anos'],
}

/** "Única" ou "A cada 2 semanas até 31/12/2026". */
export function repeatText(e: Pick<CalendarException, 'kind' | 'repeat_every' | 'repeat_unit' | 'repeat_until'>, fmt: (d: string) => string): string {
  if (e.kind === 'single' || !e.repeat_unit) return 'Única'
  const n = e.repeat_every ?? 1
  const every: Record<RepeatUnit, string> = { day: 'Todo dia', week: 'Toda semana', fortnight: 'Toda quinzena', month: 'Todo mês', year: 'Todo ano' }
  const base = n === 1 ? every[e.repeat_unit] : `A cada ${n} ${unitLabel[e.repeat_unit][1]}`
  return e.repeat_until ? `${base} até ${fmt(e.repeat_until)}` : base
}

/** Minutos de um dia a partir dos horários (prévia na tela; o total oficial vem da API). */
export function dayMinutes(d: Pick<ScheduleDay, 'start_1' | 'end_1' | 'start_2' | 'end_2'>): number {
  const m = (t: string | null) => (t && /^\d{2}:\d{2}$/.test(t) ? Number(t.slice(0, 2)) * 60 + Number(t.slice(3)) : null)
  const span = (a: string | null, b: string | null) => {
    const x = m(a)
    const y = m(b)
    return x !== null && y !== null && y > x ? y - x : 0
  }
  return span(d.start_1, d.end_1) + span(d.start_2, d.end_2)
}

export const hm = (minutes: number) => `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`
