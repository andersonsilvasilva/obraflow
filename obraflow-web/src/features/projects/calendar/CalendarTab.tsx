import FullCalendar, { type CalendarRef, type DateClickInfo, type DayCellInfo, type EventClickInfo, type EventInput, type EventSourceFuncInfo } from '@fullcalendar/react'
import dayGridPlugin from '@fullcalendar/react/daygrid'
import interactionPlugin from '@fullcalendar/react/interaction'
import ptBrLocale from '@fullcalendar/react/locales/pt-br'
import classicTheme from '@fullcalendar/react/themes/classic'
import timeGridPlugin from '@fullcalendar/react/timegrid'
import '@fullcalendar/react/skeleton.css'
import '@fullcalendar/react/themes/classic/theme.css'
import '@fullcalendar/react/themes/classic/palette.css'
import './fullcalendar.css'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarPlus, CalendarRange, Flag, Pencil, Plus, Trash2 } from 'lucide-react'
import { useCallback, useRef, useState } from 'react'
import { useToast } from '@/app/providers/ToastProvider'
import { Alert } from '@/components/feedback/Alert'
import { ConfirmDialog, Modal } from '@/components/feedback/Modal'
import { EmptyState, ErrorState, LoadingState } from '@/components/feedback/states'
import { FormField, SelectInput, TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { getErrorMessage } from '@/lib/api-error'
import { formatDate } from '@/utils/format'
import { calendarApi, repeatText, unitLabel, type CalendarException, type ExceptionInput, type RepeatUnit } from './api'

interface Props {
  uuid: string
  canEdit: boolean
  startDate: string | null
}

/** AAAA-MM-DD na data LOCAL (o FullCalendar entrega meia-noite local). */
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

/**
 * Aba Calendário da obra (CALENDARIO_OBRA_MODELO.md): lista de exceções (dias sem trabalho) ao lado do calendário
 * (FullCalendar 7 — D-P61). Dias da semana sem horário ficam acinzentados (D-P64). Ações conforme o nível na obra;
 * a API confere de novo.
 */
export function CalendarTab({ uuid, canEdit, startDate }: Props) {
  const { notify } = useToast()
  const client = useQueryClient()
  const calendarRef = useRef<CalendarRef>(null)
  const [offDays, setOffDays] = useState<number[]>([])
  const [exceptionDates, setExceptionDates] = useState<Set<string>>(new Set())
  const [year, setYear] = useState(() => new Date().getFullYear())
  const [editing, setEditing] = useState<{ row?: CalendarException; date?: string } | null>(null)
  const [removing, setRemoving] = useState<CalendarException | null>(null)

  const list = useQuery({ queryKey: ['project-calendar', uuid], queryFn: () => calendarApi.exceptions(uuid) })

  const refresh = () => {
    client.invalidateQueries({ queryKey: ['project-calendar', uuid] })
    calendarRef.current?.getApi().refetchEvents()
  }

  const fetchEvents = useCallback(
    (info: EventSourceFuncInfo, success: (events: EventInput[]) => void, failure: (err: Error) => void) => {
      const last = new Date(info.end)
      last.setDate(last.getDate() - 1)
      // função síncrona com IIFE: uma função async faria o FullCalendar encadear os próprios callbacks
      void (async () => {
        try {
          const range = await calendarApi.range(uuid, ymd(info.start), ymd(last))
          setOffDays(range.non_working_weekdays)
          setExceptionDates(new Set(range.days.map((d) => d.date)))
          success(range.days.map((d) => ({ id: `${d.exception}:${d.date}`, title: d.description, start: d.date, allDay: true, extendedProps: { exception: d.exception } })))
        } catch (e) {
          failure(e instanceof Error ? e : new Error(String(e)))
        }
      })()
    },
    [uuid],
  )

  const dayClass = (info: DayCellInfo) => {
    if (exceptionDates.has(ymd(info.date))) return 'of-day-exception'
    return offDays.includes(info.dow) ? 'of-day-off' : ''
  }

  const run = (fn: () => Promise<{ created: number }>, label: string) =>
    fn()
      .then((r) => {
        notify('success', r.created ? `${r.created} ${label} importado(s).` : `Nada a importar: ${label} já cadastrados.`)
        refresh()
      })
      .catch((e) => notify('error', getErrorMessage(e)))

  const remove = useMutation({
    mutationFn: (row: CalendarException) => calendarApi.remove(uuid, row.uuid),
    onSuccess: () => {
      notify('success', 'Exceção excluída.')
      setRemoving(null)
      refresh()
    },
    onError: (e) => notify('error', getErrorMessage(e)),
  })

  const rows = list.data ?? []

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <p className="text-muted-foreground text-sm">Dias em que não há trabalho na obra: feriados, fins de semana, paradas.</p>
        {canEdit && (
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" size="lg" onClick={() => run(() => calendarApi.importWeekends(uuid), 'fins de semana')}>
              <CalendarRange className="size-4" /> Importar sábados e domingos
            </Button>
            <Button variant="outline" size="lg" onClick={() => run(() => calendarApi.importHolidays(uuid, year), `feriados de ${year}`)}>
              <Flag className="size-4" /> Importar feriados nacionais de {year}
            </Button>
            <Button size="lg" onClick={() => setEditing({})}>
              <Plus className="size-4" /> Nova exceção
            </Button>
          </div>
        )}
      </div>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        <div className="overflow-x-auto rounded-lg border">
          {list.isPending ? (
            <LoadingState />
          ) : list.isError ? (
            <ErrorState error={list.error} onRetry={() => list.refetch()} />
          ) : rows.length === 0 ? (
            <EmptyState title="Nenhuma exceção" description="Importe os fins de semana e os feriados, ou clique num dia do calendário." />
          ) : (
            <table className="w-full text-sm">
              <caption className="sr-only">Exceções do calendário</caption>
              <thead className="bg-muted/60 text-muted-foreground text-left text-xs">
                <tr>
                  <th scope="col" className="px-3 py-2">Descrição</th>
                  <th scope="col" className="px-3 py-2">Data</th>
                  <th scope="col" className="px-3 py-2">Repetição</th>
                  <th scope="col" className="px-3 py-2">Criado por</th>
                  {canEdit && <th scope="col" className="w-20 px-3 py-2"><span className="sr-only">Ações</span></th>}
                </tr>
              </thead>
              <tbody className="divide-y">
                {rows.map((r) => (
                  <tr key={r.uuid}>
                    <td className="px-3 py-2">{r.description}</td>
                    <td className="px-3 py-2 tabular-nums">{formatDate(r.date)}</td>
                    <td className="px-3 py-2">{repeatText(r, formatDate)}</td>
                    <td className="text-muted-foreground px-3 py-2">{r.created_by?.name ?? '—'}</td>
                    {canEdit && (
                      <td className="px-3 py-2">
                        <div className="flex gap-1">
                          <Button variant="ghost" size="icon-sm" aria-label={`Editar ${r.description}`} onClick={() => setEditing({ row: r })}>
                            <Pencil />
                          </Button>
                          <Button variant="ghost" size="icon-sm" aria-label={`Excluir ${r.description}`} onClick={() => setRemoving(r)}>
                            <Trash2 />
                          </Button>
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div className="obraflow-calendar min-w-0">
          <FullCalendar
            ref={calendarRef}
            plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin, classicTheme]}
            initialView="dayGridMonth"
            locale={ptBrLocale}
            headerToolbar={{ left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' }}
            height="auto"
            events={fetchEvents}
            dayCellClass={dayClass}
            datesSet={(info) => setYear(new Date((info.start.getTime() + info.end.getTime()) / 2).getFullYear())}
            dateClick={(info: DateClickInfo) => canEdit && setEditing({ date: ymd(info.date) })}
            eventClick={(info: EventClickInfo) => {
              const row = rows.find((r) => r.uuid === info.event.extendedProps.exception)
              if (row && canEdit) setEditing({ row })
            }}
          />
          {offDays.length > 0 && <p className="text-muted-foreground mt-2 text-xs">Dias acinzentados: sem horário de trabalho (aba Horários).</p>}
        </div>
      </div>

      {editing && (
        <ExceptionModal
          uuid={uuid}
          row={editing.row}
          date={editing.date ?? startDate}
          onClose={() => setEditing(null)}
          onSaved={(msg) => {
            notify('success', msg)
            setEditing(null)
            refresh()
          }}
        />
      )}
      <ConfirmDialog
        open={removing !== null}
        title={`Excluir a exceção "${removing?.description ?? ''}"?`}
        description={removing?.kind === 'recurring' ? 'Todas as ocorrências desta repetição deixam de ser dias sem trabalho.' : 'O dia volta a ser de trabalho.'}
        confirmLabel="Excluir"
        destructive
        onConfirm={() => removing && remove.mutate(removing)}
        onCancel={() => setRemoving(null)}
      />
    </div>
  )
}

function ExceptionModal({ uuid, row, date, onClose, onSaved }: { uuid: string; row?: CalendarException; date: string | null; onClose: () => void; onSaved: (msg: string) => void }) {
  const [form, setForm] = useState<ExceptionInput>(() =>
    row
      ? { description: row.description, date: row.date, kind: row.kind, repeat_every: row.repeat_every ?? 1, repeat_unit: row.repeat_unit ?? 'week', repeat_until: row.repeat_until }
      : { description: '', date: date ?? ymd(new Date()), kind: 'single', repeat_every: 1, repeat_unit: 'week', repeat_until: null },
  )
  const set = <K extends keyof ExceptionInput>(k: K, v: ExceptionInput[K]) => setForm((f) => ({ ...f, [k]: v }))
  const recurring = form.kind === 'recurring'

  const save = useMutation({
    mutationFn: () => {
      const body: ExceptionInput = recurring ? form : { ...form, repeat_every: null, repeat_unit: null, repeat_until: null }
      return row ? calendarApi.update(uuid, row.uuid, body) : calendarApi.create(uuid, body)
    },
    onSuccess: () => onSaved(row ? 'Exceção atualizada.' : 'Exceção criada.'),
  })

  return (
    <Modal
      open
      onClose={onClose}
      title={row ? 'Editar exceção' : 'Nova exceção'}
      description="Dia em que não há trabalho na obra."
      size="md"
      footer={
        <>
          <Button variant="outline" size="lg" onClick={onClose}>
            Cancelar
          </Button>
          <Button size="lg" disabled={!form.description.trim() || !form.date || save.isPending} onClick={() => save.mutate()}>
            <CalendarPlus className="size-4" /> {row ? 'Salvar' : 'Criar'}
          </Button>
        </>
      }
    >
      {save.isError && (
        <Alert tone="danger" className="mb-3">
          {getErrorMessage(save.error)}
        </Alert>
      )}
      <div className="grid gap-3 sm:grid-cols-2">
        <FormField label="Descrição" required className="sm:col-span-2">
          <TextInput value={form.description} maxLength={200} placeholder="Ex.: Feriado da Consciência Negra" onChange={(e) => set('description', e.target.value)} autoFocus />
        </FormField>
        <FormField label="Data" required>
          <TextInput type="date" value={form.date} onChange={(e) => set('date', e.target.value)} />
        </FormField>
        <FormField label="Tipo da exceção" required>
          <SelectInput value={form.kind} onChange={(e) => set('kind', e.target.value as ExceptionInput['kind'])}>
            <option value="single">Única</option>
            <option value="recurring">Recorrente</option>
          </SelectInput>
        </FormField>
        {recurring && (
          <>
            <FormField label="Repetir a cada" required>
              <div className="flex gap-2">
                <TextInput type="number" min={1} max={365} className="w-20" value={form.repeat_every ?? 1} onChange={(e) => set('repeat_every', Math.max(1, Number(e.target.value) || 1))} />
                <SelectInput value={form.repeat_unit ?? 'week'} onChange={(e) => set('repeat_unit', e.target.value as RepeatUnit)}>
                  {(Object.keys(unitLabel) as RepeatUnit[]).map((u) => (
                    <option key={u} value={u}>
                      {unitLabel[u][(form.repeat_every ?? 1) > 1 ? 1 : 0]}
                    </option>
                  ))}
                </SelectInput>
              </div>
            </FormField>
            <FormField label="Até" hint="Em branco: até o término previsto da obra.">
              <TextInput type="date" value={form.repeat_until ?? ''} min={form.date} onChange={(e) => set('repeat_until', e.target.value || null)} />
            </FormField>
          </>
        )}
      </div>
    </Modal>
  )
}
