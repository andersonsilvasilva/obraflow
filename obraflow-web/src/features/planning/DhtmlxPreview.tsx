/**
 * PROTÓTIPO para comparação (A-58): o mesmo planejamento desenhado pelo DHTMLX Gantt Community (MIT desde a 10.0;
 * aqui a 10.0.3). Só visualização: as datas continuam vindo do servidor. Instância própria (fábrica `Gantt`), sem
 * estado global. A linha "Hoje" é desenhada à parte (na edição gratuita o marcador é recurso pago).
 * Carregado sob demanda (React.lazy).
 */
import { Gantt, type Task } from 'dhtmlx-gantt'
import 'dhtmlx-gantt/codebase/dhtmlxgantt.css'
import { useEffect, useRef } from 'react'
import { isoWeek, type PlanData } from './api'
import './dhtmlx-preview.css'

const LINK: Record<string, string> = { FS: '0', SS: '1', FF: '2', SF: '3' }

const exclusiveEnd = (ymd: string) => {
  const d = new Date(`${ymd}T00:00:00`)
  d.setDate(d.getDate() + 1)
  return d
}

export default function DhtmlxPreview({ plan, showCritical }: { plan: PlanData; showCritical: boolean }) {
  const el = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!el.current) return
    const g = Gantt.getGanttInstance()
    g.i18n.setLocale('pt')
    g.config.readonly = true
    g.config.date_format = '%Y-%m-%d'
    g.config.row_height = 40
    g.config.bar_height = 24
    g.config.scale_height = 56
    g.config.min_column_width = 160
    g.config.open_tree_initially = true
    g.config.show_progress = true
    g.config.scales = [
      { unit: 'month', step: 1, format: (d: Date) => d.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' }) },
      { unit: 'week', step: 1, format: (d: Date) => `Semana ${isoWeek(d)}` },
    ]
    g.config.columns = [
      { name: 'wbs', label: 'ID', width: 56, template: (t: Task) => String(t.wbs ?? '') },
      { name: 'text', label: 'Atividade', tree: true, width: 240 },
      { name: 'start_date', label: 'Início', align: 'center', width: 92 },
      {
        name: 'end_label',
        label: 'Término',
        align: 'center',
        width: 92,
        template: (t: Task) => String(t.end_label ?? ''),
      },
      { name: 'duration', label: 'Duração', align: 'center', width: 70 },
    ]
    g.templates.task_class = (_s: Date, _e: Date, t: Task) =>
      [t.type === 'project' ? 'of-summary' : '', showCritical && t.critical && t.type !== 'project' ? 'of-critical' : ''].join(' ')
    g.templates.task_text = (_s: Date, _e: Date, t: Task) => String(t.text ?? '')

    g.init(el.current)
    g.parse({
      data: (plan.tasks ?? []).map((t) => ({
        id: t.id,
        parent: t.parent_id ?? 0,
        text: t.name,
        wbs: t.wbs,
        start_date: new Date(`${t.start}T00:00:00`),
        end_date: t.kind === 'milestone' ? new Date(`${t.start}T00:00:00`) : exclusiveEnd(t.end),
        end_label: new Date(`${t.end}T00:00:00`).toLocaleDateString('pt-BR'),
        type: t.kind === 'summary' ? 'project' : t.kind === 'milestone' ? 'milestone' : 'task',
        progress: Number(t.percent) / 100,
        critical: t.critical,
        open: true,
      })),
      links: (plan.dependencies ?? []).map((d) => ({ id: d.id, source: d.predecessor_id, target: d.successor_id, type: LINK[d.type] ?? '0', lag: d.lag_days })),
    })

    // linha "Hoje" desenhada à parte, sobre a área das barras
    const today = () => {
      const area = g.$task_data
      if (!area) return
      area.querySelector('.of-today')?.remove()
      const line = document.createElement('div')
      line.className = 'of-today'
      line.style.left = `${g.posFromDate(new Date())}px`
      line.innerHTML = '<span>Hoje</span>'
      area.appendChild(line)
    }
    today()
    const ev = g.attachEvent('onGanttRender', today)

    return () => {
      g.detachEvent(ev)
      g.destructor()
    }
  }, [plan, showCritical])

  return <div ref={el} className="obraflow-dhtmlx h-[60vh] min-h-96 overflow-hidden rounded-lg border" />
}
