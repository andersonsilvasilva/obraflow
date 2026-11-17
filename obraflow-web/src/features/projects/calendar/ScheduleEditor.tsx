import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { RotateCcw, Save } from 'lucide-react'
import { useState } from 'react'
import { useToast } from '@/app/providers/ToastProvider'
import { Alert } from '@/components/feedback/Alert'
import { ErrorState, LoadingState } from '@/components/feedback/states'
import { TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { getErrorMessage } from '@/lib/api-error'
import { dayMinutes, hm, WEEKDAYS, type Schedule, type ScheduleInput } from './api'

interface Props {
  queryKey: unknown[]
  load: () => Promise<Schedule>
  save: (days: ScheduleInput) => Promise<Schedule>
  /** Só na obra: volta a herdar o horário da empresa. */
  reset?: () => Promise<Schedule>
  canEdit: boolean
  /** Texto de contexto (obra × empresa). */
  scope: 'project' | 'company'
}

const ROWS = [
  ['start_1', 'Entrada 1'],
  ['end_1', 'Saída 1'],
  ['start_2', 'Entrada 2'],
  ['end_2', 'Saída 2'],
] as const

const empty = (): ScheduleInput => Array.from({ length: 7 }, () => ({ start_1: null, end_1: null, start_2: null, end_2: null }))

/**
 * Grade de horários (D-P63): dias como colunas e Entrada/Saída 1 e 2 como linhas; horas por dia e por semana.
 * Dia sem horário = sem trabalho (D-P64). A validação oficial é da API (ordem e pares completos).
 */
export function ScheduleEditor(props: Props) {
  const query = useQuery({ queryKey: props.queryKey, queryFn: props.load })
  if (query.isPending) return <LoadingState />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />

  // a grade remonta quando os dados mudam (salvar/voltar ao padrão): o estado editável nasce dos dados carregados
  return <ScheduleGrid key={query.dataUpdatedAt} {...props} data={query.data} />
}

function ScheduleGrid({ queryKey, save, reset, canEdit, scope, data }: Props & { data: Schedule }) {
  const { notify } = useToast()
  const client = useQueryClient()
  const [days, setDays] = useState<ScheduleInput>(() => data.days.map(({ start_1, end_1, start_2, end_2 }) => ({ start_1, end_1, start_2, end_2 })))

  const done = (msg: string) => (s: Schedule) => {
    client.setQueryData(queryKey, s)
    notify('success', msg)
  }
  const saveM = useMutation({ mutationFn: () => save(days), onSuccess: done('Horário salvo.') })
  const resetM = useMutation({ mutationFn: () => reset!(), onSuccess: done('A obra voltou a usar o horário da empresa.') })

  const source = data.source
  const set = (d: number, k: keyof ScheduleInput[number], v: string) => setDays((all) => all.map((row, i) => (i === d ? { ...row, [k]: v || null } : row)))
  const week = days.reduce((sum, d) => sum + dayMinutes(d), 0)
  const error = saveM.error ?? resetM.error

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p className="text-muted-foreground text-sm">
          {scope === 'company'
            ? 'Horário-padrão da empresa: as obras dela usam este horário até alguém ajustar o da obra.'
            : source === 'project'
              ? 'Esta obra tem horário próprio.'
              : source === 'company'
                ? 'Esta obra usa o horário-padrão da empresa. Ao salvar, ela passa a ter horário próprio.'
                : 'Nenhum horário definido (nem na empresa). Dias sem horário contam como sem trabalho.'}
        </p>
        <p className="text-sm font-medium">
          Total na semana: <span className="tabular-nums">{hm(week)} h</span>
        </p>
      </div>

      {error && (
        <Alert tone="danger" className="mb-3">
          {getErrorMessage(error)}
        </Alert>
      )}

      <div className="overflow-x-auto rounded-lg border">
        <table className="w-full min-w-[46rem] text-sm">
          <caption className="sr-only">Horário de trabalho por dia da semana</caption>
          <thead className="bg-muted/60 text-muted-foreground text-left text-xs">
            <tr>
              <th scope="col" className="px-3 py-2">Entradas e saídas</th>
              {WEEKDAYS.map((d) => (
                <th key={d} scope="col" className="px-2 py-2">
                  {d}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y">
            {ROWS.map(([key, label]) => (
              <tr key={key}>
                <th scope="row" className="px-3 py-2 text-left font-medium">
                  {label}
                </th>
                {days.map((d, i) => (
                  <td key={i} className="px-2 py-1.5">
                    <TextInput
                      type="time"
                      aria-label={`${label} — ${WEEKDAYS[i]}`}
                      value={d[key] ?? ''}
                      disabled={!canEdit}
                      onChange={(e) => set(i, key, e.target.value)}
                    />
                  </td>
                ))}
              </tr>
            ))}
            <tr className="bg-muted/30">
              <th scope="row" className="px-3 py-2 text-left font-medium">
                Horas no dia
              </th>
              {days.map((d, i) => (
                <td key={i} className="text-muted-foreground px-2 py-2 tabular-nums">
                  {dayMinutes(d) ? `${hm(dayMinutes(d))} h` : 'Sem trabalho'}
                </td>
              ))}
            </tr>
          </tbody>
        </table>
      </div>

      {canEdit && (
        <div className="mt-4 flex flex-wrap justify-end gap-2">
          {reset && source === 'project' && (
            <Button variant="outline" size="lg" disabled={resetM.isPending} onClick={() => resetM.mutate()}>
              <RotateCcw className="size-4" /> Voltar ao padrão da empresa
            </Button>
          )}
          <Button variant="outline" size="lg" onClick={() => setDays(empty())}>
            Limpar
          </Button>
          <Button size="lg" disabled={saveM.isPending} onClick={() => saveM.mutate()}>
            <Save className="size-4" /> Salvar horário
          </Button>
        </div>
      )}
    </div>
  )
}
