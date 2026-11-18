import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarCheck } from 'lucide-react'
import { Alert } from '@/components/feedback/Alert'
import { Button } from '@/components/ui/button'
import { useToast } from '@/app/providers/ToastProvider'
import { calendarApi, WEEKDAYS } from '@/features/projects/calendar/api'
import { projectsApi } from '@/features/projects/api'
import { getErrorMessage } from '@/lib/api-error'
import { formatDate } from '@/utils/format'
import { toDate, toYmd } from './api'

interface Props {
  uuid: string
  projectStart: string | null
  projectEnd: string | null
  planStart: string | null
  planEnd: string | null
  canEditProject: boolean
}

const short = (ymd: string) => formatDate(ymd).slice(0, 5)
const daysBetween = (a: string, b: string) => Math.round((+toDate(b) - +toDate(a)) / 86_400_000)

/**
 * Compara o planejamento com o período da obra (início e entrega prevista) e explica a diferença: o início do
 * planejamento é o primeiro DIA ÚTIL (D-P66) — feriados e dias sem horário são mostrados um a um. Se o planejamento
 * termina depois da entrega prevista, oferece levar o término para a obra (como a "Projeção de término" do Brickup).
 */
export function PeriodNotice({ uuid, projectStart, projectEnd, planStart, planEnd, canEditProject }: Props) {
  const { notify } = useToast()
  const client = useQueryClient()
  const startShift = !!(projectStart && planStart && planStart > projectStart)
  const before = startShift ? toDate(planStart!) : null
  if (before) before.setDate(before.getDate() - 1)

  // dias entre o início da obra e o início do planejamento: por que não são úteis?
  const cal = useQuery({
    queryKey: ['project-calendar-range', uuid, projectStart, before ? toYmd(before) : null],
    queryFn: () => calendarApi.range(uuid, projectStart!, toYmd(before!)),
    enabled: startShift && daysBetween(projectStart!, planStart!) <= 60,
  })
  const update = useMutation({
    mutationFn: () => projectsApi.update(uuid, { expected_end_date: planEnd }),
    onSuccess: () => {
      notify('success', `Previsão de entrega da obra atualizada para ${formatDate(planEnd!)}.`)
      client.invalidateQueries({ queryKey: ['project', uuid] })
      client.invalidateQueries({ queryKey: ['projects'] })
    },
    onError: (e) => notify('error', getErrorMessage(e)),
  })

  const lateBy = planEnd && projectEnd ? daysBetween(projectEnd, planEnd) : 0
  if (!startShift && lateBy <= 0) return null

  const reasons: string[] = []
  if (startShift && cal.data) {
    const exceptions = new Map<string, string>()
    for (const d of cal.data.days) exceptions.set(d.date, d.description)
    for (let d = toDate(projectStart!); d < toDate(planStart!); d.setDate(d.getDate() + 1)) {
      const ymd = toYmd(d)
      reasons.push(exceptions.has(ymd) ? `${short(ymd)}: ${exceptions.get(ymd)}` : cal.data.non_working_weekdays.includes(d.getDay()) ? `${short(ymd)}: ${WEEKDAYS[d.getDay()].toLowerCase()}, sem horário de trabalho` : `${short(ymd)}: não útil`)
    }
  }

  return (
    <Alert tone={lateBy > 0 ? 'warning' : 'info'} className="mb-3">
      <div className="flex flex-col gap-1.5 text-sm">
        {startShift && (
          <p>
            A obra começa em <strong>{formatDate(projectStart!)}</strong>, mas o planejamento começa em <strong>{formatDate(planStart!)}</strong>, o primeiro dia útil
            {reasons.length > 0 ? <> ({reasons.join('; ')}).</> : '.'} Os dias úteis vêm das abas Horários e Calendário.
          </p>
        )}
        {lateBy > 0 && (
          <div className="flex flex-wrap items-center gap-2">
            <p className="flex-1">
              O planejamento termina em <strong>{formatDate(planEnd!)}</strong>, <strong>{lateBy} dia(s) depois</strong> da entrega prevista da obra ({formatDate(projectEnd!)}).
            </p>
            {canEditProject && (
              <Button size="sm" variant="outline" disabled={update.isPending} onClick={() => update.mutate()}>
                <CalendarCheck className="size-3.5" /> Usar {short(planEnd!)} como entrega prevista
              </Button>
            )}
          </div>
        )}
      </div>
    </Alert>
  )
}
