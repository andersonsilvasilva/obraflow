import { Gantt, Tooltip, Willow, type IApi, type IColumnConfig } from '@svar-ui/react-gantt'
import '@svar-ui/react-gantt/all.css'
import './gantt.css'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, ChevronsDownUp, ChevronsUpDown, CornerDownRight, Download, FileSpreadsheet, FileStack, Flag, Layers, Percent, Plus, Route, Save, Settings, Sparkles, Tag, Trash2, Upload, ZoomIn, ZoomOut } from 'lucide-react'
import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react'
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
import { fromGanttLink, isoWeek, planningApi, toGanttLink, toGanttTasks, toYmd, type ImportPreview, type PlanData, type PlanTask } from './api'
import { PeriodNotice } from './PeriodNotice'
import { TaskDialog } from './TaskDialog'

type Mode = 'grid' | 'all' | 'chart'
type Zoom = 'day' | 'week' | 'month'

/** Colunas opcionais da tabela do Gantt (configurações de visualização — D-P70). */
const COLUMN_LABELS = {
  start: 'Início',
  end: 'Término',
  duration_days: 'Duração (dias)',
  preds: 'Predecessoras',
  unit: 'Un. medida',
  quantity: 'Quantidade',
} as const
type ColumnKey = keyof typeof COLUMN_LABELS
type Pref = { mode: Mode; zoom: Zoom; critical: boolean; columns: Record<ColumnKey, boolean> }
const DEFAULT_PREF: Pref = {
  mode: 'all',
  zoom: 'week',
  critical: false,
  columns: { start: true, end: true, duration_days: true, preds: false, unit: false, quantity: false },
}

const PREF = (uuid: string) => `obraflow.planning.${uuid}`
function readPref(uuid: string): Pref {
  try {
    const saved = JSON.parse(localStorage.getItem(PREF(uuid)) ?? '{}')
    return { ...DEFAULT_PREF, ...saved, columns: { ...DEFAULT_PREF.columns, ...(saved.columns ?? {}) } }
  } catch {
    return DEFAULT_PREF
  }
}

/** Protótipo de comparação (A-58), carregado só quando escolhido. */
const BryntumPreview = lazy(() => import('./BryntumPreview'))

const monthFmt = new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric' })

const monthShort = new Intl.DateTimeFormat('pt-BR', { month: 'short' })
const SCALES: Record<Zoom, { unit: string; step: number; format: (d: Date) => string }[]> = {
  day: [
    { unit: 'month', step: 1, format: (d) => monthFmt.format(d) },
    { unit: 'day', step: 1, format: (d) => String(d.getDate()) },
  ],
  week: [
    { unit: 'month', step: 1, format: (d) => monthFmt.format(d) },
    { unit: 'week', step: 1, format: (d) => `Semana ${isoWeek(d)}` },
  ],
  month: [
    { unit: 'year', step: 1, format: (d) => String(d.getFullYear()) },
    { unit: 'month', step: 1, format: (d) => monthShort.format(d).replace('.', '') },
  ],
}
/** Largura da célula da escala: semana larga (como o Brickup), para o nome caber na barra. */
const CELL: Record<Zoom, number> = { day: 44, week: 160, month: 130 }

/**
 * Planejamento da obra (Fase 6A, referência: Brickup). O servidor é a fonte da verdade: dias úteis, reprogramação
 * pelas dependências e caminho crítico (D-P66/D-P67). O Gantt (SVAR, MIT — D-P69) só desenha e captura as ações
 * (arrastar barra, ligar atividades, abrir edição), que viram chamadas à API.
 */
export function PlanningTab({ project, canEditProject, onOpenCalendar }: { project: { uuid: string; start_date: string | null; expected_end_date: string | null }; canEditProject: boolean; onOpenCalendar: () => void }) {
  const { can } = useAuth()
  const { notify } = useToast()
  const client = useQueryClient()
  const uuid = project.uuid
  const [pref, setPrefState] = useState(() => readPref(uuid))
  const [compare, setCompare] = useState<number | null>(null)
  const [editing, setEditing] = useState<{ task?: PlanTask; parentId?: number | null; kind?: PlanTask['kind'] } | null>(null)
  const [removing, setRemoving] = useState<PlanTask | null>(null)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [baselineName, setBaselineName] = useState<string | null>(null)
  const [settings, setSettings] = useState(false)
  // comparação de bibliotecas de Gantt (A-58): preferência do navegador
  const [engine, setEngineState] = useState<'svar' | 'bryntum'>(() => {
    try {
      return localStorage.getItem('obraflow.planning.gantt-engine') === 'bryntum' ? 'bryntum' : 'svar'
    } catch {
      return 'svar'
    }
  })
  const setEngine = (v: 'svar' | 'bryntum') => {
    setEngineState(v)
    try {
      localStorage.setItem('obraflow.planning.gantt-engine', v)
    } catch {
      /* sem armazenamento: só nesta tela */
    }
  }
  const [importing, setImporting] = useState(false)
  const ganttApi = useRef<IApi | null>(null)
  const key = ['schedule', uuid, compare]

  const query = useQuery({ queryKey: key, queryFn: () => planningApi.get(uuid, compare), enabled: can('schedules.view') })
  const plan = query.data
  const canEdit = (plan?.abilities.edit ?? false) && canEditProject

  /** Expandir/recolher todas as etapas no Gantt. */
  const toggleAll = (open: boolean) => {
    const api = ganttApi.current
    if (!api) return
    for (const t of query.data?.tasks ?? []) {
      if (t.kind === 'summary') void api.exec('open-task', { id: t.id, mode: open })
    }
  }
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
  const baselines = plan!.baselines?.length ?? 0
  const soon6B = 'Com os recursos das atividades (parte 6B)'
  const zoomStep = (dir: 1 | -1) => {
    const order: Zoom[] = ['month', 'week', 'day']
    setPref({ zoom: order[Math.min(2, Math.max(0, order.indexOf(pref.zoom) + dir))] })
  }

  return (
    <div>
      {/* topo: como no Brickup — linha de base, exceções (calendário) e IA (Fase 9) */}
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <label className="text-muted-foreground inline-flex items-center gap-2 text-sm" title={soon6B}>
          <input type="checkbox" role="switch" disabled className="accent-(--primary)" /> Visualizar recursos <SoonBadge />
        </label>
        <div className="flex flex-wrap gap-2">
          {canEdit && (
            <Button size="sm" variant="outline" disabled={baselines >= 10} onClick={() => setBaselineName(`Linha de base ${baselines + 1}`)}>
              <Save className="size-3.5" /> Criar linha de base ({baselines}/10)
            </Button>
          )}
          <Button size="sm" variant="outline" onClick={onOpenCalendar} title="Feriados, paradas e fins de semana da obra (aba Calendário)">
            <CalendarClock className="size-3.5" /> Exceções
          </Button>
          <Button size="sm" variant="outline" disabled title="Fase 9 — IA assistiva, com revisão humana">
            <Sparkles className="size-3.5" /> Planejamento com IA
          </Button>
        </div>
      </div>

      {/* totais de custo (6B) e indicadores do cronograma */}
      <div className="mb-3 grid gap-2 sm:grid-cols-3 lg:grid-cols-5 2xl:grid-cols-11">
        {['Custo', 'Com BDI', 'BDI', 'Desconto', 'Valor final'].map((l) => (
          <Kpi key={l} label={l} value="—" hint={soon6B} />
        ))}
        <Kpi label="Início" value={formatDateOrDash(summary.start)} sub={`Obra: ${formatDateOrDash(project.start_date)}`} />
        <Kpi
          label="Término"
          value={formatDateOrDash(summary.end)}
          sub={`Entrega prevista: ${formatDateOrDash(project.expected_end_date)}`}
          tone={summary.end && project.expected_end_date && summary.end > project.expected_end_date ? 'danger' : undefined}
        />
        <Kpi label="Executado" value={`${Number(summary.percent).toLocaleString('pt-BR')}%`} />
        <Kpi label="Atividades" value={String(summary.tasks)} />
        <Kpi label="Caminho crítico" value={String(summary.critical)} tone="danger" />
        <Kpi label="Atrasadas" value={String(summary.late)} tone={summary.late ? 'danger' : undefined} />
      </div>

      <PeriodNotice
        uuid={uuid}
        projectStart={project.start_date}
        projectEnd={project.expected_end_date}
        planStart={summary.start}
        planEnd={summary.end}
        canEditProject={canEditProject}
        durationMode={plan!.schedule!.duration_mode}
      />

      {/* barra de ações: análise à esquerda; incluir, importar, exibição e zoom à direita */}
      <div className="mb-2 flex flex-wrap items-center gap-2">
        <Button size="sm" variant={pref.critical ? 'default' : 'outline'} aria-pressed={pref.critical} onClick={() => setPref({ critical: !pref.critical })}>
          <Route className="size-3.5" /> Caminho crítico
        </Button>
        <label className="text-muted-foreground inline-flex items-center gap-2 text-sm">
          Comparar com
          <SelectInput className="h-8 w-56 py-0 text-sm" value={compare ?? ''} onChange={(e) => setCompare(e.target.value ? Number(e.target.value) : null)} disabled={!baselines}>
          <option value="">{baselines ? 'Nenhuma linha de base' : 'Sem linha de base'}</option>
          {plan!.baselines?.map((b) => (
            <option key={b.number} value={b.number}>
              {b.number}. {b.name}
            </option>
          ))}
          </SelectInput>
        </label>
        <Button size="sm" variant="outline" disabled title={soon6B}>
          <Percent className="size-3.5" /> BDI
        </Button>
        <Button size="sm" variant="outline" disabled title={soon6B}>
          <Tag className="size-3.5" /> Desconto
        </Button>

        <div className="ml-auto flex flex-wrap items-center gap-1">
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
              <Button size="sm" variant="outline" disabled={tasks.length > 0} title={tasks.length > 0 ? 'A importação preenche um planejamento vazio (use "Deletar tudo" antes)' : 'Importar planilha'} onClick={() => setImporting(true)}>
                <Upload className="size-3.5" /> Importar
              </Button>
            </>
          )}
          <Button size="sm" variant="outline" disabled title={soon6B}>
            <Download className="size-3.5" /> Exportar
          </Button>
          <Button size="icon-sm" variant="outline" aria-label="Configurações de visualização" title="Configurações de visualização" onClick={() => setSettings(true)}>
            <Settings className="size-3.5" />
          </Button>
          <Button size="icon-sm" variant="outline" aria-label="Expandir tudo" title="Expandir tudo" onClick={() => toggleAll(true)}>
            <ChevronsUpDown className="size-3.5" />
          </Button>
          <Button size="icon-sm" variant="outline" aria-label="Recolher tudo" title="Recolher tudo" onClick={() => toggleAll(false)}>
            <ChevronsDownUp className="size-3.5" />
          </Button>
          <Button size="icon-sm" variant="outline" aria-label="Diminuir zoom" title="Diminuir zoom" disabled={pref.zoom === 'month'} onClick={() => zoomStep(-1)}>
            <ZoomOut className="size-3.5" />
          </Button>
          <Button size="icon-sm" variant="outline" aria-label="Aumentar zoom" title="Aumentar zoom" disabled={pref.zoom === 'day'} onClick={() => zoomStep(1)}>
            <ZoomIn className="size-3.5" />
          </Button>
          {canEdit && (
            <Button size="icon-sm" variant="outline" aria-label="Deletar tudo" title="Deletar tudo" onClick={() => setConfirmDelete(true)}>
              <Trash2 className="size-3.5" />
            </Button>
          )}
        </div>
      </div>

      <p className="text-muted-foreground mb-2 text-xs">
        {plan!.schedule!.duration_mode === 'working' ? (
          <>Duração em <strong>dias úteis</strong> da obra (abas Horários e Calendário). </>
        ) : (
          <>Duração em <strong>dias corridos</strong> (feriados e dias sem trabalho aparecem destacados). </>
        )}
        {canEdit && (tasks.length === 0 ? 'Comece por "+ Atividade" ou "+ Etapa", ou importe a planilha.' : 'Edite nome, início e duração direto na tabela (duplo clique na célula); arraste as barras e ligue as bolinhas para criar dependências.')}
        {plan!.compared_baseline && ' A coluna "Desvio" compara o término com a linha de base (+ = atrasou).'}
      </p>

      <div className="mb-2 flex items-center justify-end gap-1 text-xs" role="group" aria-label="Visual do Gantt (comparação)">
        <span className="text-muted-foreground mr-1">Visual do Gantt:</span>
        {([
          ['svar', 'Atual (SVAR)'],
          ['bryntum', 'Bryntum Community (protótipo)'],
        ] as const).map(([v, label]) => (
          <button key={v} type="button" onClick={() => setEngine(v)} className={cn('rounded-md border px-2 py-1', engine === v ? 'border-primary bg-primary/10 text-primary font-medium' : 'text-muted-foreground')}>
            {label}
          </button>
        ))}
      </div>
      {engine === 'bryntum' ? (
        <Suspense fallback={<LoadingState />}>
          <BryntumPreview plan={plan!} />
        </Suspense>
      ) : (
        <GanttView
          uuid={uuid}
          tasks={tasks}
          plan={plan!}
          mode={pref.mode}
          zoom={pref.zoom}
          columns={pref.columns}
          showCritical={pref.critical}
          readonly={!canEdit}
          apiRef={ganttApi}
          onOpen={(id) => {
            const t = byId.get(id)
            if (t) setEditing({ task: t })
          }}
          onRowAction={(action, id) => {
            const t = byId.get(id)
            if (!t) return
            if (action === 'below') setEditing({ kind: 'task', parentId: t.parent_id })
            else if (action === 'child') setEditing({ kind: 'task', parentId: t.id })
            else setRemoving(t)
          }}
          onRun={run}
        />
      )}

      {settings && (
        <SettingsDialog
          pref={pref}
          durationMode={plan!.schedule!.duration_mode}
          canEdit={canEdit}
          onDurationMode={(mode) => run(planningApi.update(uuid, { duration_mode: mode }), mode === 'working' ? 'Duração agora em dias úteis.' : 'Duração agora em dias corridos.')}
          onClose={() => setSettings(false)}
          onSave={(p) => (setPref(p), setSettings(false))}
        />
      )}
      {importing && (
        <ImportDialog
          uuid={uuid}
          onClose={() => setImporting(false)}
          onDone={(d) => (apply(d), setImporting(false))}
        />
      )}
      {editing && (
        <TaskDialog
          uuid={uuid}
          tasks={tasks}
          task={editing.task}
          kind={editing.kind}
          parentId={editing.parentId}
          durationMode={plan!.schedule!.duration_mode}
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
        open={removing !== null}
        title={`Excluir "${removing?.wbs ?? ''} ${removing?.name ?? ''}"?`}
        description={removing?.kind === 'summary' ? 'A etapa sai com tudo o que está dentro dela, e as dependências também.' : 'A atividade e as dependências dela serão excluídas.'}
        confirmLabel="Excluir"
        destructive
        onConfirm={() => removing && (run(planningApi.removeTask(uuid, removing.id), 'Excluída.'), setRemoving(null))}
        onCancel={() => setRemoving(null)}
      />
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

function Kpi({ label, value, tone, hint, sub }: { label: string; value: string; tone?: 'danger'; hint?: string; sub?: string }) {
  return (
    <div className="bg-card rounded-lg border px-3 py-2" title={hint}>
      <p className="text-muted-foreground flex items-center gap-1 text-xs">
        {label} {hint && <SoonBadge />}
      </p>
      <p className={cn('text-base font-semibold tabular-nums', tone === 'danger' && value !== '0' && 'text-destructive', hint && 'text-muted-foreground')}>{value}</p>
      {sub && <p className="text-muted-foreground text-[0.7rem] tabular-nums">{sub}</p>}
    </div>
  )
}

function RowButton({ label, danger, onClick, children }: { label: string; danger?: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      onClick={(e) => (e.stopPropagation(), onClick())}
      className={cn('hover:bg-primary-soft rounded p-1', danger ? 'text-destructive' : 'text-muted-foreground hover:text-primary')}
    >
      {children}
    </button>
  )
}

function SoonBadge() {
  return <span className="bg-muted text-muted-foreground rounded px-1 text-[0.65rem] font-medium">6B</span>
}

function SettingsDialog({
  pref,
  durationMode,
  canEdit,
  onDurationMode,
  onClose,
  onSave,
}: {
  pref: Pref
  durationMode: 'calendar' | 'working'
  canEdit: boolean
  onDurationMode: (mode: 'calendar' | 'working') => void
  onClose: () => void
  onSave: (p: Partial<Pref>) => void
}) {
  const [mode, setMode] = useState(pref.mode)
  const [columns, setColumns] = useState(pref.columns)
  return (
    <Modal
      open
      onClose={onClose}
      title="Configurações de visualização — cronograma"
      description="Preferência sua, guardada neste navegador para esta obra."
      size="md"
      footer={
        <>
          <Button variant="outline" size="lg" onClick={() => setColumns(DEFAULT_PREF.columns)}>
            Restaurar colunas
          </Button>
          <Button variant="outline" size="lg" onClick={onClose}>
            Cancelar
          </Button>
          <Button size="lg" onClick={() => onSave({ mode, columns })}>
            Salvar preferências
          </Button>
        </>
      }
    >
      <fieldset className="mb-4" disabled={!canEdit}>
        <legend className="mb-2 text-sm font-medium">Contar a duração em</legend>
        <p className="text-muted-foreground mb-2 text-xs">Vale para todos (é do planejamento). As durações ficam; as datas são recalculadas.</p>
        <div className="flex flex-wrap gap-4 text-sm">
          {(
            [
              ['calendar', 'Dias corridos (como o Brickup)'],
              ['working', 'Dias úteis da obra (sem feriados e dias sem horário)'],
            ] as const
          ).map(([m, label]) => (
            <label key={m} className="flex items-center gap-2">
              <input type="radio" name="duration-mode" className="accent-(--primary) size-4" checked={durationMode === m} onChange={() => onDurationMode(m)} />
              {label}
            </label>
          ))}
        </div>
      </fieldset>
      <fieldset className="mb-4">
        <legend className="mb-2 text-sm font-medium">Colunas visíveis</legend>
        <p className="text-muted-foreground mb-2 text-xs">ID e Atividade aparecem sempre. As colunas de custo e venda chegam com os recursos (6B).</p>
        <div className="grid grid-cols-2 gap-2 text-sm">
          {(Object.keys(COLUMN_LABELS) as ColumnKey[]).map((k) => (
            <label key={k} className="flex items-center gap-2">
              <input type="checkbox" className="accent-(--primary) size-4" checked={columns[k]} onChange={(e) => setColumns({ ...columns, [k]: e.target.checked })} />
              {COLUMN_LABELS[k]}
            </label>
          ))}
        </div>
      </fieldset>
      <fieldset>
        <legend className="mb-2 text-sm font-medium">Modo de visualização</legend>
        <div className="flex flex-wrap gap-1">
          {(
            [
              ['grid', 'Somente tabela'],
              ['all', 'Layout dividido'],
              ['chart', 'Somente Gantt'],
            ] as const
          ).map(([m, label]) => (
            <Button key={m} size="sm" variant={mode === m ? 'default' : 'outline'} aria-pressed={mode === m} onClick={() => setMode(m)}>
              {label}
            </Button>
          ))}
        </div>
      </fieldset>
    </Modal>
  )
}

function GanttView({
  uuid,
  tasks,
  plan,
  mode,
  zoom,
  columns: visible,
  showCritical,
  readonly,
  apiRef,
  onOpen,
  onRowAction,
  onRun,
}: {
  uuid: string
  tasks: PlanTask[]
  plan: PlanData
  mode: Mode
  zoom: Zoom
  columns: Record<ColumnKey, boolean>
  apiRef: React.MutableRefObject<IApi | null>
  showCritical: boolean
  readonly: boolean
  onOpen: (id: number) => void
  onRowAction: (action: 'below' | 'child' | 'delete', id: number) => void
  onRun: (p: Promise<PlanData>, ok?: string) => void
}) {
  const durationLabel = plan.schedule?.duration_mode === 'working' ? 'Dias úteis' : 'Duração (dias)'
  const gTasks = useMemo(() => {
    const wbsOf = new Map(tasks.map((t) => [t.id, t.wbs]))
    return toGanttTasks(tasks).map((g, i) => ({
      ...g,
      preds: tasks[i].predecessors.map((p) => `${wbsOf.get(p.task_id) ?? '?'}${p.type === 'FS' ? '' : p.type}${p.lag ? (p.lag > 0 ? `+${p.lag}` : p.lag) : ''}`).join('; '),
      unit: tasks[i].unit_code ?? '',
      quantity: tasks[i].quantity !== null ? Number(tasks[i].quantity).toLocaleString('pt-BR') : '',
    }))
  }, [tasks])
  const links = useMemo(
    () => (plan.dependencies ?? []).map((d) => ({ id: d.id, source: d.predecessor_id, target: d.successor_id, type: toGanttLink[d.type], lag: d.lag_days })),
    [plan.dependencies],
  )
  const byId = useMemo(() => new Map(tasks.map((t) => [t.id, t])), [tasks])
  const handlers = useRef({ onOpen, onRun, byId, onRowAction })
  useEffect(() => {
    handlers.current = { onOpen, onRun, byId, onRowAction }
  }, [onOpen, onRun, byId, onRowAction])
  const [markers] = useState(() => [{ start: new Date(), text: 'Hoje' }])
  const [ganttApi, setGanttApi] = useState<IApi | null>(null)

  // dias não úteis da obra no período (horário + exceções) para destacar no Gantt
  const range = plan.summary?.start && plan.summary?.end ? clampRange(plan.summary.start, plan.summary.end) : null
  const cal = useQuery({ queryKey: ['project-calendar-range', uuid, range?.[0], range?.[1]], queryFn: () => calendarApi.range(uuid, range![0], range![1]), enabled: !!range })
  const off = useMemo(() => ({ days: new Set((cal.data?.days ?? []).map((d) => d.date)), weekdays: new Set(cal.data?.non_working_weekdays ?? []) }), [cal.data])
  const highlight = useCallback((date: Date, unit: string) => (unit === 'day' && (off.weekdays.has(date.getDay()) || off.days.has(toYmd(date))) ? 'wx-weekend' : ''), [off])

  const init = useCallback(
    (api: IApi) => {
      apiRef.current = api
      setGanttApi(api)
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
        if (!t) return false
        const g = (ev.task ?? {}) as { text?: string; start?: Date; end?: Date; duration_days?: number | string }
        const body: Record<string, unknown> = {}
        if (typeof g.text === 'string' && g.text.trim() && g.text.trim() !== t.name) body.name = g.text.trim()
        if (t.kind === 'task' && g.duration_days !== undefined && Number(g.duration_days) !== t.duration_days && Number(g.duration_days) >= 1) {
          body.duration_days = Math.round(Number(g.duration_days))
        }
        if (t.kind !== 'summary' && g.start && toYmd(g.start) !== t.start) body.planned_start = toYmd(g.start)
        if (t.kind === 'task' && g.start && g.end && body.duration_days === undefined) {
          // arrastar a ponta muda o tamanho: o fim do Gantt é exclusivo
          const sameLength = Math.round((+g.end - +g.start) / 86_400_000) === t.calendar_days
          if (!sameLength) {
            const last = new Date(g.end)
            last.setDate(last.getDate() - 1)
            body.planned_end = toYmd(last)
          }
        }
        if (Object.keys(body).length === 0) return false
        handlers.current.onRun(planningApi.updateTask(uuid, t.id, body))
        // a barra/célula acompanha na hora; o cronograma recalculado chega em seguida (etapa: só o nome)
        return t.kind !== 'summary' || Object.keys(body).every((k) => k === 'name')
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
    [uuid, apiRef],
  )

  const columns = useMemo<IColumnConfig[]>(
    () => ([
      // ações da linha (como no Brickup): atividade abaixo, sub atividade, excluir — só para quem edita
      ...(readonly
        ? []
        : [
            {
              id: 'actions',
              header: '',
              width: 84,
              cell: ({ row }: { row: { id?: unknown } }) => (
                <span className="flex items-center gap-0.5" onDoubleClick={(e) => e.stopPropagation()}>
                  <RowButton label="Atividade abaixo" onClick={() => handlers.current.onRowAction('below', Number(row.id))}>
                    <Plus className="size-3.5" />
                  </RowButton>
                  <RowButton label="Sub atividade" onClick={() => handlers.current.onRowAction('child', Number(row.id))}>
                    <CornerDownRight className="size-3.5" />
                  </RowButton>
                  <RowButton label="Excluir" danger onClick={() => handlers.current.onRowAction('delete', Number(row.id))}>
                    <Trash2 className="size-3.5" />
                  </RowButton>
                </span>
              ),
            },
          ]),
      { id: 'wbs', header: 'ID', width: 64 },
      { id: 'text', header: 'Atividade', flexgrow: 1, editor: readonly ? undefined : 'text' },
      { id: 'start', header: 'Início', width: 100, editor: readonly ? undefined : 'datepicker', template: (v: Date) => v.toLocaleDateString('pt-BR') },
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
      { id: 'duration_days', header: durationLabel, width: 96, align: 'center', editor: readonly ? undefined : 'text' },
      { id: 'preds', header: 'Predecessoras', width: 110 },
      { id: 'unit', header: 'Un. medida', width: 80, align: 'center' },
      { id: 'quantity', header: 'Quantidade', width: 90, align: 'right' },
      ...(plan.compared_baseline
        ? [{ id: 'delta', header: 'Desvio', width: 70, align: 'center' as const, template: (v: number | null) => (v === null ? '—' : v > 0 ? `+${v}` : String(v)) }]
        : []),
    ] as IColumnConfig[]).filter((c) => !(String(c.id) in visible) || visible[c.id as ColumnKey]),
    [plan.compared_baseline, visible, readonly, durationLabel],
  )

  // tela flutuante ao passar o mouse na faixa: o texto completo e os dados da atividade
  const TipContent = useCallback(
    ({ data }: { data: unknown }) => {
      const id = (data as { task?: { id?: unknown } }).task?.id
      const t = id !== undefined ? handlers.current.byId.get(Number(id)) : undefined
      return t ? <TaskTip task={t} durationLabel={durationLabel} /> : null
    },
    [durationLabel],
  )

  const Bar = useCallback(
    ({ data }: { data: { text?: string; critical?: boolean; progress?: number; type?: string; $w?: number } }) => {
      // o nome vai dentro da barra quando cabe; senão, logo à direita dela (como o Brickup)
      const fits = (data.$w ?? 0) >= (data.text?.length ?? 0) * 6.6 + 18
      return (
        <>
          {showCritical && data.critical && data.type !== 'summary' && <span className="of-bar-critical" aria-hidden />}
          {!!data.progress && data.type !== 'milestone' && <span className="of-bar-progress" style={{ width: `${data.progress}%` }} aria-hidden />}
          {data.type === 'milestone' ? <span className="of-bar-label-out">{data.text}</span> : <span className={fits ? 'of-bar-label' : 'of-bar-label-out'}>{data.text}</span>}
        </>
      )
    },
    [showCritical],
  )

  return (
    <div className="obraflow-gantt h-[60vh] min-h-96 overflow-hidden rounded-lg border">
      <Willow fonts={false}>
        <Tooltip api={ganttApi ?? undefined} content={TipContent}>
          <Gantt
            init={init}
            tasks={gTasks}
            links={links}
            columns={columns}
            scales={SCALES[zoom]}
            cellWidth={CELL[zoom]}
            cellHeight={40}
            scaleHeight={30}
            gridWidth={mode === 'all' ? 600 : undefined}
            displayMode={mode}
            readonly={readonly}
            taskTemplate={Bar}
            highlightTime={highlight}
            markers={markers}
          />
        </Tooltip>
      </Willow>
    </div>
  )
}

const KIND_LABEL: Record<PlanTask['kind'], string> = { task: 'Atividade', summary: 'Etapa', milestone: 'Marco' }
const br = (ymd: string) => new Date(`${ymd}T00:00:00`).toLocaleDateString('pt-BR')

/** Conteúdo da tela flutuante da faixa do Gantt. */
function TaskTip({ task: t, durationLabel }: { task: PlanTask; durationLabel: string }) {
  const percent = Number(t.percent)
  return (
    <div className="of-gantt-tip">
      <p className="text-muted-foreground text-xs">
        {t.wbs} · {KIND_LABEL[t.kind]}
        {t.critical && <span className="text-destructive font-medium"> · caminho crítico</span>}
        {t.late && <span className="text-destructive font-medium"> · atrasada</span>}
      </p>
      <p className="font-semibold">{t.name}</p>
      <dl className="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs">
        <dt className="text-muted-foreground">{t.kind === 'milestone' ? 'Data' : 'Período'}</dt>
        <dd>{t.kind === 'milestone' ? br(t.start) : `${br(t.start)} a ${br(t.end)}`}</dd>
        {t.kind !== 'milestone' && (
          <>
            <dt className="text-muted-foreground">{durationLabel}</dt>
            <dd>{t.duration_days}</dd>
          </>
        )}
        {t.quantity !== null && (
          <>
            <dt className="text-muted-foreground">Quantidade</dt>
            <dd>
              {Number(t.quantity).toLocaleString('pt-BR')} {t.unit_code ?? ''}
            </dd>
          </>
        )}
        {percent > 0 && (
          <>
            <dt className="text-muted-foreground">Executado</dt>
            <dd>{percent.toLocaleString('pt-BR')}%</dd>
          </>
        )}
        {t.baseline && (
          <>
            <dt className="text-muted-foreground">Linha de base</dt>
            <dd>
              {br(t.baseline.start)} a {br(t.baseline.end)}
              {t.baseline.delta_days !== 0 && ` (${t.baseline.delta_days > 0 ? '+' : ''}${t.baseline.delta_days} dia(s))`}
            </dd>
          </>
        )}
      </dl>
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
