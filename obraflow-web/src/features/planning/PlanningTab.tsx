import { Gantt, Willow, type IApi, type IColumnConfig } from '@svar-ui/react-gantt'
import '@svar-ui/react-gantt/all.css'
import './gantt.css'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, Download, FileSpreadsheet, FileStack, Flag, Layers, Plus, Route, Save, Sparkles, Trash2 } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useToast } from '@/app/providers/ToastProvider'
import { Alert } from '@/components/feedback/Alert'
import { ConfirmDialog, Modal } from '@/components/feedback/Modal'
import { EmptyState, ErrorState, LoadingState } from '@/components/feedback/states'
import { FormField, SelectInput, TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-context'
import { budgetsApi } from '@/features/budgets/api'
import { calendarApi } from '@/features/projects/calendar/api'
import { getErrorMessage } from '@/lib/api-error'
import { cn } from '@/lib/utils'
import { formatDateOrDash } from '@/utils/format'
import { fromGanttLink, planningApi, toGanttLink, toGanttTasks, toYmd, type ImportPreview, type PlanData, type PlanTask } from './api'
import { TaskDialog } from './TaskDialog'

type Mode = 'grid' | 'all' | 'chart'
type Zoom = 'day' | 'week' | 'month'

const PREF = (uuid: string) => `obraflow.planning.${uuid}`
function readPref(uuid: string): { mode: Mode; zoom: Zoom; critical: boolean } {
  try {
    return { mode: 'all', zoom: 'day', critical: true, ...JSON.parse(localStorage.getItem(PREF(uuid)) ?? '{}') }
  } catch {
    return { mode: 'all', zoom: 'day', critical: true }
  }
}

const monthFmt = new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric' })
const monthShort = new Intl.DateTimeFormat('pt-BR', { month: 'short' })
const SCALES: Record<Zoom, { unit: string; step: number; format: (d: Date) => string }[]> = {
  day: [
    { unit: 'month', step: 1, format: (d) => monthFmt.format(d) },
    { unit: 'day', step: 1, format: (d) => String(d.getDate()) },
  ],
  week: [
    { unit: 'month', step: 1, format: (d) => monthFmt.format(d) },
    { unit: 'week', step: 1, format: (d) => `${d.getDate()}/${d.getMonth() + 1}` },
  ],
  month: [
    { unit: 'year', step: 1, format: (d) => String(d.getFullYear()) },
    { unit: 'month', step: 1, format: (d) => monthShort.format(d).replace('.', '') },
  ],
}
const CELL: Record<Zoom, number> = { day: 34, week: 70, month: 90 }

/**
 * Planejamento da obra (Fase 6A, referência: Brickup). O servidor é a fonte da verdade: dias úteis, reprogramação
 * pelas dependências e caminho crítico (D-P66/D-P67). O Gantt (SVAR, MIT — D-P69) só desenha e captura as ações
 * (arrastar barra, ligar atividades, abrir edição), que viram chamadas à API.
 */
export function PlanningTab({ project, canEditProject }: { project: { uuid: string; start_date: string | null }; canEditProject: boolean }) {
  const { can } = useAuth()
  const { notify } = useToast()
  const client = useQueryClient()
  const uuid = project.uuid
  const [pref, setPrefState] = useState(() => readPref(uuid))
  const [compare, setCompare] = useState<number | null>(null)
  const [editing, setEditing] = useState<{ task?: PlanTask; parent?: PlanTask; kind?: PlanTask['kind'] } | null>(null)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [baselineName, setBaselineName] = useState<string | null>(null)
  const key = ['schedule', uuid, compare]

  const query = useQuery({ queryKey: key, queryFn: () => planningApi.get(uuid, compare), enabled: can('schedules.view') })
  const plan = query.data
  const canEdit = (plan?.abilities.edit ?? false) && canEditProject

  const setPref = (p: Partial<typeof pref>) => {
    const next = { ...pref, ...p }
    setPrefState(next)
    try {
      localStorage.setItem(PREF(uuid), JSON.stringify(next))
    } catch {
      /* preferência só desta sessão */
    }
  }
  const apply = useCallback(
    (d: PlanData) => {
      client.setQueryData(key, d)
      client.invalidateQueries({ queryKey: ['project', uuid] })
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [client, uuid, compare],
  )
  const run = useCallback(
    (p: Promise<PlanData>, ok?: string) =>
      p.then((d) => (apply(d), ok && notify('success', ok))).catch((e) => (notify('error', getErrorMessage(e)), query.refetch())),
    [apply, notify, query],
  )

  if (!can('schedules.view')) return <EmptyState title="Sem acesso ao planejamento" description="Peça a permissão de consulta do planejamento ao administrador." />
  if (query.isPending) return <LoadingState />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  if (!plan!.schedule) return <StartPanel uuid={uuid} canEdit={canEdit} onCreated={apply} />

  const tasks = plan!.tasks ?? []
  const summary = plan!.summary!
  const byId = new Map(tasks.map((t) => [t.id, t]))

  return (
    <div>
      <div className="mb-3 grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
        <Kpi label="Início" value={formatDateOrDash(summary.start)} />
        <Kpi label="Término" value={formatDateOrDash(summary.end)} />
        <Kpi label="Executado" value={`${Number(summary.percent).toLocaleString('pt-BR')}%`} />
        <Kpi label="Atividades" value={String(summary.tasks)} />
        <Kpi label="No caminho crítico" value={String(summary.critical)} tone="danger" />
        <Kpi label="Atrasadas" value={String(summary.late)} tone={summary.late ? 'danger' : undefined} />
      </div>

      <div className="mb-3 flex flex-wrap items-center gap-2">
        {canEdit && (
          <>
            <Button size="sm" onClick={() => setEditing({ kind: 'task' })}>
              <Plus className="size-3.5" /> Atividade
            </Button>
            <Button size="sm" variant="outline" onClick={() => setEditing({ kind: 'summary' })}>
              <Layers className="size-3.5" /> Etapa
            </Button>
            <Button size="sm" variant="outline" onClick={() => setEditing({ kind: 'milestone' })}>
              <Flag className="size-3.5" /> Marco
            </Button>
          </>
        )}
        <Button size="sm" variant={pref.critical ? 'default' : 'outline'} aria-pressed={pref.critical} onClick={() => setPref({ critical: !pref.critical })}>
          <Route className="size-3.5" /> Caminho crítico
        </Button>
        {canEdit && (
          <Button size="sm" variant="outline" disabled={(plan!.baselines?.length ?? 0) >= 10} onClick={() => setBaselineName(`Linha de base ${(plan!.baselines?.length ?? 0) + 1}`)}>
            <Save className="size-3.5" /> Criar linha de base ({plan!.baselines?.length ?? 0}/10)
          </Button>
        )}
        <SelectInput className="w-52" aria-label="Comparar com" value={compare ?? ''} onChange={(e) => setCompare(e.target.value ? Number(e.target.value) : null)} disabled={!plan!.baselines?.length}>
          <option value="">Comparar com: nenhuma</option>
          {plan!.baselines?.map((b) => (
            <option key={b.number} value={b.number}>
              {b.number}. {b.name}
            </option>
          ))}
        </SelectInput>
        <div className="ml-auto flex flex-wrap gap-1">
          {(
            [
              ['grid', 'Tabela'],
              ['all', 'Dividido'],
              ['chart', 'Gantt'],
            ] as const
          ).map(([m, label]) => (
            <Button key={m} size="sm" variant={pref.mode === m ? 'default' : 'outline'} aria-pressed={pref.mode === m} onClick={() => setPref({ mode: m })}>
              {label}
            </Button>
          ))}
          <SelectInput className="w-28" aria-label="Zoom" value={pref.zoom} onChange={(e) => setPref({ zoom: e.target.value as Zoom })}>
            <option value="day">Dia</option>
            <option value="week">Semana</option>
            <option value="month">Mês</option>
          </SelectInput>
          {canEdit && (
            <Button size="sm" variant="outline" onClick={() => setConfirmDelete(true)} title="Deletar tudo">
              <Trash2 className="size-3.5" />
            </Button>
          )}
        </div>
      </div>

      <p className="text-muted-foreground mb-2 text-xs">
        <CalendarClock className="mr-1 inline size-3.5" />
        Duração em <strong>dias úteis</strong> da obra (abas Horários e Calendário). {canEdit && 'Arraste as barras para mudar datas e ligue as bolinhas para criar dependências; clique duas vezes para editar.'}
        {plan!.compared_baseline && ' A coluna "Desvio" compara o término com a linha de base escolhida (+ = atrasou).'}
      </p>

      {tasks.length === 0 ? (
        <EmptyState title="Planejamento vazio" description="Inclua etapas e atividades." />
      ) : (
        <GanttView
          uuid={uuid}
          tasks={tasks}
          plan={plan!}
          mode={pref.mode}
          zoom={pref.zoom}
          showCritical={pref.critical}
          readonly={!canEdit}
          onOpen={(id) => {
            const t = byId.get(id)
            if (t) setEditing({ task: t })
          }}
          onRun={run}
        />
      )}

      {editing && (
        <TaskDialog
          uuid={uuid}
          tasks={tasks}
          task={editing.task}
          kind={editing.kind}
          canEdit={canEdit}
          onClose={() => setEditing(null)}
          onSaved={(d, msg) => {
            apply(d)
            notify('success', msg)
            setEditing(null)
          }}
        />
      )}
      {baselineName !== null && (
        <Modal
          open
          onClose={() => setBaselineName(null)}
          title="Criar linha de base"
          description="Congela as datas atuais para comparar depois (até 10)."
          size="md"
          footer={
            <>
              <Button variant="outline" size="lg" onClick={() => setBaselineName(null)}>
                Cancelar
              </Button>
              <Button size="lg" disabled={!baselineName.trim()} onClick={() => (run(planningApi.addBaseline(uuid, baselineName.trim()), 'Linha de base criada.'), setBaselineName(null))}>
                Criar
              </Button>
            </>
          }
        >
          <FormField label="Nome" required>
            <TextInput value={baselineName} maxLength={100} onChange={(e) => setBaselineName(e.target.value)} autoFocus />
          </FormField>
        </Modal>
      )}
      <ConfirmDialog
        open={confirmDelete}
        title="Apagar todo o planejamento?"
        description="Todas as etapas, atividades e dependências serão apagadas. Não é possível se houver linha de base ou avanço registrado."
        confirmLabel="Apagar tudo"
        destructive
        onConfirm={() =>
          planningApi
            .remove(uuid)
            .then(() => (setConfirmDelete(false), client.invalidateQueries({ queryKey: ['schedule', uuid] }), notify('success', 'Planejamento apagado.')))
            .catch((e) => (setConfirmDelete(false), notify('error', getErrorMessage(e))))
        }
        onCancel={() => setConfirmDelete(false)}
      />
    </div>
  )
}

function Kpi({ label, value, tone }: { label: string; value: string; tone?: 'danger' }) {
  return (
    <div className="bg-card rounded-lg border px-3 py-2">
      <p className="text-muted-foreground text-xs">{label}</p>
      <p className={cn('text-base font-semibold tabular-nums', tone === 'danger' && value !== '0' && 'text-destructive')}>{value}</p>
    </div>
  )
}

function GanttView({
  uuid,
  tasks,
  plan,
  mode,
  zoom,
  showCritical,
  readonly,
  onOpen,
  onRun,
}: {
  uuid: string
  tasks: PlanTask[]
  plan: PlanData
  mode: Mode
  zoom: Zoom
  showCritical: boolean
  readonly: boolean
  onOpen: (id: number) => void
  onRun: (p: Promise<PlanData>, ok?: string) => void
}) {
  const gTasks = useMemo(() => toGanttTasks(tasks), [tasks])
  const links = useMemo(
    () => (plan.dependencies ?? []).map((d) => ({ id: d.id, source: d.predecessor_id, target: d.successor_id, type: toGanttLink[d.type], lag: d.lag_days })),
    [plan.dependencies],
  )
  const byId = useMemo(() => new Map(tasks.map((t) => [t.id, t])), [tasks])
  const handlers = useRef({ onOpen, onRun, byId })
  useEffect(() => {
    handlers.current = { onOpen, onRun, byId }
  }, [onOpen, onRun, byId])
  const [markers] = useState(() => [{ start: new Date(), text: 'Hoje' }])

  // dias não úteis da obra no período (horário + exceções) para destacar no Gantt
  const range = plan.summary?.start && plan.summary?.end ? clampRange(plan.summary.start, plan.summary.end) : null
  const cal = useQuery({ queryKey: ['project-calendar-range', uuid, range?.[0], range?.[1]], queryFn: () => calendarApi.range(uuid, range![0], range![1]), enabled: !!range })
  const off = useMemo(() => ({ days: new Set((cal.data?.days ?? []).map((d) => d.date)), weekdays: new Set(cal.data?.non_working_weekdays ?? []) }), [cal.data])
  const highlight = useCallback((date: Date, unit: string) => (unit === 'day' && (off.weekdays.has(date.getDay()) || off.days.has(toYmd(date))) ? 'wx-weekend' : ''), [off])

  const init = useCallback(
    (api: IApi) => {
      // tudo que mudaria a estrutura localmente vira chamada à API (o servidor recalcula); o Gantt não muda sozinho
      api.intercept('show-editor', ({ id }) => (handlers.current.onOpen(Number(id)), false))
      api.intercept('add-task', () => false)
      api.intercept('delete-task', () => false)
      api.intercept('move-task', () => false)
      api.intercept('copy-task', () => false)
      api.intercept('indent-task', () => false)
      api.intercept('update-task', (ev) => {
        if (ev.inProgress) return true
        const t = handlers.current.byId.get(Number(ev.id))
        const { start, end } = ev.task ?? {}
        if (!t || !start || t.kind === 'summary') return false
        const body: Record<string, unknown> = { planned_start: toYmd(start) }
        if (end && t.kind === 'task') {
          const last = new Date(end)
          last.setDate(last.getDate() - 1) // fim do Gantt é exclusivo
          const sameLength = Math.round((+end - +start) / 86_400_000) === t.calendar_days
          if (!sameLength) body.planned_end = toYmd(last)
        }
        handlers.current.onRun(planningApi.updateTask(uuid, t.id, body))
        return false
      })
      api.intercept('add-link', ({ link }) => {
        if (link?.source && link?.target) {
          handlers.current.onRun(
            planningApi.addDependency(uuid, { predecessor_id: Number(link.source), successor_id: Number(link.target), type: fromGanttLink[String(link.type)] ?? 'FS' }),
            'Dependência criada.',
          )
        }
        return false
      })
      api.intercept('delete-link', ({ id }) => (handlers.current.onRun(planningApi.removeDependency(uuid, Number(id)), 'Dependência removida.'), false))
    },
    [uuid],
  )

  const columns = useMemo<IColumnConfig[]>(
    () => [
      { id: 'wbs', header: 'ID', width: 64 },
      { id: 'text', header: 'Atividade', flexgrow: 1 },
      { id: 'start', header: 'Início', width: 92, template: (v: Date) => v.toLocaleDateString('pt-BR') },
      {
        id: 'end',
        header: 'Término',
        width: 92,
        template: (v: Date, row: { type?: string } & Record<string, unknown>) => {
          const d = new Date(v)
          if (row.type !== 'milestone') d.setDate(d.getDate() - 1)
          return d.toLocaleDateString('pt-BR')
        },
      },
      { id: 'duration_days', header: 'Dias úteis', width: 80, align: 'center' },
      ...(plan.compared_baseline
        ? [{ id: 'delta', header: 'Desvio', width: 70, align: 'center' as const, template: (v: number | null) => (v === null ? '—' : v > 0 ? `+${v}` : String(v)) }]
        : []),
    ],
    [plan.compared_baseline],
  )

  const Bar = useCallback(
    ({ data }: { data: { text?: string; critical?: boolean; progress?: number; type?: string } }) => (
      <>
        {showCritical && data.critical && data.type !== 'summary' && <span className="of-bar-critical" aria-hidden />}
        {!!data.progress && data.type !== 'milestone' && <span className="of-bar-progress" style={{ width: `${data.progress}%` }} aria-hidden />}
        {data.type !== 'milestone' && <span className="of-bar-label">{data.text}</span>}
      </>
    ),
    [showCritical],
  )

  return (
    <div className="obraflow-gantt h-[60vh] min-h-96 overflow-hidden rounded-lg border">
      <Willow fonts={false}>
        <Gantt
          init={init}
          tasks={gTasks}
          links={links}
          columns={columns}
          scales={SCALES[zoom]}
          cellWidth={CELL[zoom]}
          cellHeight={34}
          displayMode={mode}
          readonly={readonly}
          taskTemplate={Bar}
          highlightTime={highlight}
          markers={markers}
        />
      </Willow>
    </div>
  )
}

/** O calendário da API aceita até 400 dias por consulta: limita o destaque ao começo do cronograma. */
function clampRange(start: string, end: string): [string, string] {
  const s = new Date(`${start}T00:00:00`)
  const max = new Date(s)
  max.setDate(max.getDate() + 399)
  const e = new Date(`${end}T00:00:00`)
  return [start, toYmd(e < max ? e : max)]
}

// ------------------------------------------------------------------ criação (D-P68)

function StartPanel({ uuid, canEdit, onCreated }: { uuid: string; canEdit: boolean; onCreated: (d: PlanData) => void }) {
  const { notify } = useToast()
  const [dialog, setDialog] = useState<'budget' | 'import' | null>(null)
  const blank = useMutation({ mutationFn: () => planningApi.create(uuid, { origin: 'blank' }), onSuccess: (d) => (onCreated(d), notify('success', 'Planejamento criado.')), onError: (e) => notify('error', getErrorMessage(e)) })

  return (
    <div className="mx-auto max-w-2xl py-6 text-center">
      <h2 className="text-lg font-semibold">Escolha o formato para criar o planejamento</h2>
      <p className="text-muted-foreground mb-5 text-sm">A duração é contada em dias úteis da obra (abas Horários e Calendário).</p>
      {!canEdit ? (
        <Alert tone="info">Esta obra ainda não tem planejamento. Peça a quem tem permissão de editar o planejamento.</Alert>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          <Option icon={FileStack} title="Do orçamento aprovado" text="Etapas e itens do orçamento aprovado da obra, com quantidades." onClick={() => setDialog('budget')} />
          <Option icon={FileSpreadsheet} title="Importar planilha" text="Planilha no modelo do planejamento (com pré-visualização)." onClick={() => setDialog('import')} />
          <Option icon={Plus} title="Criar do zero" text="Começa vazio: inclua etapas, atividades e marcos." onClick={() => blank.mutate()} />
          <Option icon={Sparkles} title="Inteligência artificial" text="Em breve (Fase 9 — IA assistiva, com revisão humana)." disabled />
        </div>
      )}
      {dialog === 'budget' && <BudgetDialog uuid={uuid} onClose={() => setDialog(null)} onCreated={(d) => (onCreated(d), setDialog(null), notify('success', 'Planejamento criado a partir do orçamento.'))} />}
      {dialog === 'import' && <ImportDialog uuid={uuid} onClose={() => setDialog(null)} onDone={(d) => (onCreated(d), setDialog(null))} />}
    </div>
  )
}

function Option({ icon: Icon, title, text, onClick, disabled }: { icon: typeof Plus; title: string; text: string; onClick?: () => void; disabled?: boolean }) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      className="bg-card hover:border-primary hover:bg-primary-soft/40 flex items-start gap-3 rounded-lg border p-4 text-left disabled:cursor-not-allowed disabled:opacity-60"
    >
      <Icon className="text-primary mt-0.5 size-5 shrink-0" aria-hidden />
      <span>
        <span className="block font-medium">{title}</span>
        <span className="text-muted-foreground block text-sm">{text}</span>
      </span>
    </button>
  )
}

function BudgetDialog({ uuid, onClose, onCreated }: { uuid: string; onClose: () => void; onCreated: (d: PlanData) => void }) {
  const budgets = useQuery({ queryKey: ['budgets', 'approved', uuid], queryFn: () => budgetsApi.list({ project_uuid: uuid, status: 'approved', per_page: 100 }) })
  const options = (budgets.data?.data ?? []).filter((b) => b.current_version?.status === 'approved')
  const [selected, setSelected] = useState('')
  const chosen = options.find((b) => b.uuid === selected)
  const run = useMutation({ mutationFn: () => planningApi.create(uuid, { origin: 'budget', budget_uuid: chosen!.uuid, version: chosen!.current_version!.number }), onSuccess: onCreated })
  return (
    <Modal
      open
      onClose={onClose}
      title="Planejamento a partir do orçamento aprovado"
      description="As etapas do orçamento viram etapas e os itens viram atividades (com quantidade e unidade). Depois, ajuste durações e dependências."
      size="md"
      footer={
        <>
          <Button variant="outline" size="lg" onClick={onClose}>
            Cancelar
          </Button>
          <Button size="lg" disabled={!chosen || run.isPending} onClick={() => run.mutate()}>
            Criar planejamento
          </Button>
        </>
      }
    >
      {run.isError && (
        <Alert tone="danger" className="mb-3">
          {getErrorMessage(run.error)}
        </Alert>
      )}
      {budgets.isPending ? (
        <LoadingState />
      ) : options.length === 0 ? (
        <EmptyState title="Nenhum orçamento aprovado nesta obra" description="Aprove um orçamento da obra em Orçamentos." />
      ) : (
        <FormField label="Orçamento (versão aprovada)" required>
          <SelectInput value={selected} onChange={(e) => setSelected(e.target.value)}>
            <option value="">Selecione…</option>
            {options.map((b) => (
              <option key={b.uuid} value={b.uuid}>
                {b.code} — {b.title} (v{b.current_version?.number})
              </option>
            ))}
          </SelectInput>
        </FormField>
      )}
    </Modal>
  )
}

function ImportDialog({ uuid, onClose, onDone }: { uuid: string; onClose: () => void; onDone: (d: PlanData) => void }) {
  const { notify } = useToast()
  const [file, setFile] = useState<File | null>(null)
  const [preview, setPreview] = useState<ImportPreview | null>(null)
  const check = useMutation({ mutationFn: (f: File) => planningApi.importPreview(uuid, f), onSuccess: setPreview })
  const run = useMutation({
    mutationFn: async () => {
      await planningApi.importFile(uuid, file!)
      return planningApi.get(uuid)
    },
    onSuccess: (d) => (notify('success', 'Planejamento importado.'), onDone(d)),
  })
  const error = check.error ?? run.error
  return (
    <Modal
      open
      onClose={onClose}
      title="Importar planilha do planejamento"
      description="Colunas: Tipo, Item (1, 1.1, 1.1.1…), Descrição, datas planejadas e executadas, quantidade e unidade. Linhas de recurso (Material, Serviços…) entram na 6B."
      size="xl"
      footer={
        <>
          <Button variant="outline" size="lg" onClick={onClose}>
            Cancelar
          </Button>
          <Button size="lg" disabled={!preview || preview.summary.error > 0 || preview.summary.ok === 0 || run.isPending} onClick={() => run.mutate()}>
            Importar {preview ? `${preview.summary.ok} linha(s)` : ''}
          </Button>
        </>
      }
    >
      {error && (
        <Alert tone="danger" className="mb-3">
          {getErrorMessage(error)}
        </Alert>
      )}
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <input
          type="file"
          accept=".xlsx"
          aria-label="Planilha"
          className="text-sm"
          onChange={(e) => {
            const f = e.target.files?.[0] ?? null
            setFile(f)
            setPreview(null)
            if (f) check.mutate(f)
          }}
        />
        <a className="text-primary inline-flex items-center gap-1 text-sm underline-offset-2 hover:underline" href={planningApi.templateUrl(uuid)}>
          <Download className="size-3.5" /> Baixar a planilha-modelo
        </a>
      </div>
      {check.isPending && <LoadingState label="Lendo a planilha…" />}
      {preview && (
        <>
          <p className="mb-2 text-sm">
            {preview.summary.ok} para importar · {preview.summary.skip} ignorada(s) · <span className={preview.summary.error ? 'text-destructive font-medium' : ''}>{preview.summary.error} com erro</span>
          </p>
          <div className="max-h-80 overflow-auto rounded-lg border">
            <table className="w-full text-sm">
              <caption className="sr-only">Pré-visualização</caption>
              <thead className="bg-muted/60 text-muted-foreground sticky top-0 text-left text-xs">
                <tr>
                  <th scope="col" className="px-2 py-1">Linha</th>
                  <th scope="col" className="px-2 py-1">Item</th>
                  <th scope="col" className="px-2 py-1">Descrição</th>
                  <th scope="col" className="px-2 py-1">Resultado</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {preview.rows.map((r) => (
                  <tr key={r.line} className={r.status === 'error' ? 'bg-destructive-soft' : r.status === 'skip' ? 'text-muted-foreground' : ''}>
                    <td className="px-2 py-1 tabular-nums">{r.line}</td>
                    <td className="px-2 py-1 font-mono text-xs">{r.task?.wbs ?? r.data.item}</td>
                    <td className="px-2 py-1">{r.data.name}</td>
                    <td className="px-2 py-1 text-xs">
                      {r.status === 'ok' ? (r.task?.kind === 'summary' ? 'Etapa' : 'Atividade') : r.status === 'skip' ? 'Ignorada' : 'Erro'}
                      {[...r.errors, ...r.warnings].map((m) => (
                        <span key={m} className="block">
                          {m}
                        </span>
                      ))}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </Modal>
  )
}
