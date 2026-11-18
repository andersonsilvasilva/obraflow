/**
 * PROTÓTIPO para comparação (A-58): o mesmo planejamento desenhado pela Bryntum Gantt Community (MIT, v8.0.0).
 * Só visualização: o servidor continua calculando as datas (as atividades vão como "agendadas manualmente", para o
 * motor da Bryntum não recalcular nada). Carregado sob demanda (React.lazy) para não pesar no restante do sistema.
 */
import { LocaleManager } from '@bryntum/core-community'
import '@bryntum/gantt-community/locales/gantt.locale.PtBr.js'
import { BryntumGantt, type BryntumGanttProps } from '@bryntum/gantt-react-community'
import '@bryntum/core-community/fontawesome/css/fontawesome.css'
import '@bryntum/core-community/fontawesome/css/solid.css'
import '@bryntum/gantt-community/gantt.css'
import '@bryntum/gantt-community/svalbard-light.css'
import { useMemo } from 'react'
import type { PlanData, PlanTask } from './api'
import './bryntum-preview.css'

LocaleManager.applyLocale('PtBr')

const DEP: Record<string, number> = { SS: 0, SF: 1, FS: 2, FF: 3 } // códigos da Bryntum

interface Node {
  id: number
  name: string
  wbs: string
  startDate: string
  endDate: string
  manuallyScheduled: true
  percentDone: number
  expanded: boolean
  cls?: string
  children?: Node[]
}

function exclusiveEnd(ymd: string): string {
  const d = new Date(`${ymd}T00:00:00`)
  d.setDate(d.getDate() + 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function tree(tasks: PlanTask[]): Node[] {
  const byParent = new Map<number | null, PlanTask[]>()
  for (const t of tasks) byParent.set(t.parent_id, [...(byParent.get(t.parent_id) ?? []), t])
  const build = (parent: number | null): Node[] =>
    (byParent.get(parent) ?? []).map((t) => {
      const children = build(t.id)
      return {
        id: t.id,
        name: t.name,
        wbs: t.wbs,
        startDate: t.start,
        endDate: t.kind === 'milestone' ? t.start : exclusiveEnd(t.end),
        manuallyScheduled: true,
        percentDone: Number(t.percent),
        expanded: true,
        cls: t.kind === 'summary' ? 'of-summary' : undefined,
        ...(children.length ? { children } : {}),
      }
    })
  return build(null)
}

export default function BryntumPreview({ plan }: { plan: PlanData }) {
  const props = useMemo<BryntumGanttProps>(() => {
    const tasks = plan.tasks ?? []
    return {
      readOnly: true,
      viewPreset: 'weekAndDayLetter',
      rowHeight: 40,
      barMargin: 8,
      columns: [
        { type: 'wbs', text: 'ID', width: 60 },
        { type: 'name', text: 'Atividade', width: 260 },
        { type: 'startdate', text: 'Início', width: 110 },
        { type: 'enddate', text: 'Término', width: 110 },
        { type: 'duration', text: 'Duração', width: 90 },
      ],
      project: {
        tasks: tree(tasks),
        dependencies: (plan.dependencies ?? []).map((d) => ({ id: d.id, fromTask: d.predecessor_id, toTask: d.successor_id, type: DEP[d.type] ?? 2, lag: d.lag_days })),
      },
      taskTooltipFeature: true,
    }
  }, [plan])

  return (
    <div className="obraflow-bryntum h-[60vh] min-h-96 overflow-hidden rounded-lg border">
      <BryntumGantt {...props} />
    </div>
  )
}
