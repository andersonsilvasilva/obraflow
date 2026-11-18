import { describe, expect, it } from 'vitest'
import { fromGanttLink, isoWeek, toGanttLink, toGanttTasks, toYmd, type PlanTask } from './api'

const base: PlanTask = {
  id: 1, parent_id: null, wbs: '1', name: 'DEMO', kind: 'task', unit_code: null, quantity: null, start: '2026-03-02', end: '2026-03-06',
  duration_days: 5, calendar_days: 5, actual_start: null, actual_end: null, percent: '40.00', from_budget: false, critical: true, float: 0,
  late: false, predecessors: [], baseline: { start: '2026-03-02', end: '2026-03-04', delta_days: 2 },
}

describe('planejamento → Gantt (6A)', () => {
  it('término do Gantt é exclusivo; marco tem início = fim; raiz vira parent 0', () => {
    const [t, m] = toGanttTasks([base, { ...base, id: 2, parent_id: 1, kind: 'milestone', start: '2026-03-09', end: '2026-03-09', duration_days: 0 }])
    expect(toYmd(t.start)).toBe('2026-03-02')
    expect(toYmd(t.end)).toBe('2026-03-07')
    expect(t).toMatchObject({ parent: 0, progress: 40, critical: true, delta: 2, duration_days: 5 })
    expect(toYmd(m.end)).toBe('2026-03-09')
    expect(m.parent).toBe(1)
  })

  it('tipos de dependência ida e volta (FS = término→início)', () => {
    expect(toGanttLink.FS).toBe('e2s')
    for (const t of ['FS', 'SS', 'FF', 'SF'] as const) expect(fromGanttLink[toGanttLink[t]]).toBe(t)
  })

  it('semana ISO para a escala semanal ("Semana 47", como no Brickup)', () => {
    expect(isoWeek(new Date(2026, 10, 17))).toBe(47)
    expect(isoWeek(new Date(2026, 0, 1))).toBe(1)
    expect(isoWeek(new Date(2027, 0, 1))).toBe(53)
  })
})
