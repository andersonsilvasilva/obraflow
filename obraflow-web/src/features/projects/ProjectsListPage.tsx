import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { Banknote, ClipboardList, LayoutGrid, List, Pencil, Plus, RefreshCw, ShoppingCart, Trash2, Users } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useToast } from '@/app/providers/ToastProvider'
import { ProgressIndicator, StatusBadge } from '@/components/data-display/indicators'
import { ConfirmDialog } from '@/components/feedback/Modal'
import { EmptyState, ErrorState, LoadingState } from '@/components/feedback/states'
import { SearchInput, SelectInput } from '@/components/forms/fields'
import { ActionMenu } from '@/components/ui/action-menu'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-context'
import { EntityFormModal } from '@/features/registry/components/EntityFormModal'
import { getErrorMessage } from '@/lib/api-error'
import { cn } from '@/lib/utils'
import { formatDateOrDash } from '@/utils/format'
import { atLeast, projectsApi, statusName, statusTone, transitions, type Project } from './api'
import { projectFields, projectToForm, useProjectLookups } from './form'

type Situation = '' | 'active' | 'inactive'
type View = 'grid' | 'list'

const VIEW_KEY = 'obraflow.projects.view'
const SOON = 'em breve'

/** Indicadores do topo que dependem de fases futuras (A-46: aparecem com "—", nunca com valor inventado). */
const FUTURE_KPIS = [
  { label: 'Despesas do dia', phase: 'Financeiro' },
  { label: 'Receitas do dia', phase: 'Financeiro' },
  { label: 'Despesas em aberto', phase: 'Financeiro' },
  { label: 'Receitas em aberto', phase: 'Financeiro' },
  { label: 'Saúde financeira', phase: 'Financeiro' },
  { label: 'Pedidos em aberto', phase: 'Compras' },
]

function readView(): View {
  try {
    return localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'grid'
  } catch {
    return 'grid'
  }
}

/**
 * Painel de obras (A-46, referência: Brickup): abas Todas/Ativas/Inativas, busca e filtros, indicadores no topo e
 * cards por obra (ou lista). Só obras que o usuário pode ver (D-P24); ações conforme o nível na obra.
 */
export function ProjectsListPage() {
  const navigate = useNavigate()
  const { can } = useAuth()
  const { notify } = useToast()
  const client = useQueryClient()
  const lookups = useProjectLookups()

  const [situation, setSituation] = useState<Situation>('active')
  const [term, setTerm] = useState('')
  const [q, setQ] = useState('')
  const [filters, setFilters] = useState({ customer_uuid: '', technical_manager_uuid: '', foreman_uuid: '' })
  const [page, setPage] = useState(1)
  const [view, setView] = useState<View>(readView)
  const [form, setForm] = useState<{ row?: Project } | null>(null)
  const [removing, setRemoving] = useState<Project | null>(null)

  useEffect(() => {
    const t = setTimeout(() => {
      setQ(term.trim())
      setPage(1)
    }, 300)
    return () => clearTimeout(t)
  }, [term])

  const query = useQuery({
    queryKey: ['projects', 'panel', situation, q, filters, page],
    queryFn: () => projectsApi.list({ situation, q, ...filters, page, per_page: 24 }),
    placeholderData: keepPreviousData,
  })

  const changeView = (v: View) => {
    setView(v)
    try {
      localStorage.setItem(VIEW_KEY, v)
    } catch {
      /* preferência só desta sessão */
    }
  }
  const setFilter = (k: keyof typeof filters, v: string) => {
    setFilters((f) => ({ ...f, [k]: v }))
    setPage(1)
  }
  const refresh = () => client.invalidateQueries({ queryKey: ['projects'] })

  const menu = (p: Project) => {
    const edit = atLeast(p.access_level, 'edit')
    return [
      { label: 'Mudar status', icon: RefreshCw, disabled: !edit || transitions[p.status].length === 0, onClick: () => navigate(`/obras/${p.uuid}?acao=status`) },
      { label: 'Editar', icon: Pencil, disabled: !edit, onClick: () => setForm({ row: p }) },
      { label: 'Efetivos (equipe)', icon: Users, onClick: () => navigate(`/obras/${p.uuid}?aba=equipe`) },
      { label: 'Acompanhamento financeiro', icon: Banknote, disabled: true, badge: SOON },
      { label: 'Cotações', icon: ShoppingCart, disabled: true, badge: SOON },
      { label: 'RDO', icon: ClipboardList, disabled: true, badge: SOON },
      { label: 'Excluir', icon: Trash2, destructive: true, disabled: !can('projects.manage'), onClick: () => setRemoving(p) },
    ]
  }

  const rows = query.data?.data ?? []
  const meta = query.data?.meta

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Obras</h1>
          <p className="text-muted-foreground text-sm">Obras que você pode acessar.</p>
        </div>
        <Button size="lg" disabled={!can('projects.manage')} onClick={() => setForm({})}>
          <Plus className="size-4" /> Adicionar obra
        </Button>
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <div className="flex gap-1" role="tablist" aria-label="Situação das obras">
          {(
            [
              ['', 'Todas'],
              ['active', 'Ativas'],
              ['inactive', 'Inativas'],
            ] as const
          ).map(([value, label]) => (
            <Button
              key={label}
              role="tab"
              aria-selected={situation === value}
              variant={situation === value ? 'default' : 'outline'}
              size="lg"
              onClick={() => (setSituation(value), setPage(1))}
            >
              {label}
            </Button>
          ))}
        </div>
        <div className="ml-auto flex flex-wrap gap-2">
          <SearchInput className="w-56" placeholder="Buscar por código ou nome" aria-label="Buscar obras" value={term} onChange={(e) => setTerm(e.target.value)} />
          <FilterSelect label="Cliente" value={filters.customer_uuid} options={lookups.customers} onChange={(v) => setFilter('customer_uuid', v)} />
          <FilterSelect label="Responsável técnico" value={filters.technical_manager_uuid} options={lookups.employees} onChange={(v) => setFilter('technical_manager_uuid', v)} />
          <FilterSelect label="Encarregado" value={filters.foreman_uuid} options={lookups.employees} onChange={(v) => setFilter('foreman_uuid', v)} />
        </div>
      </div>

      <div className="mb-4 flex gap-3 overflow-x-auto pb-1" aria-label="Indicadores">
        <Kpi label="Obras" value={meta ? String(meta.total) : '…'} />
        {FUTURE_KPIS.map((k) => (
          <Kpi key={k.label} label={k.label} value="—" hint={`Disponível com o módulo ${k.phase}`} />
        ))}
      </div>

      <div className="mb-3 flex justify-end gap-1">
        <Button variant={view === 'list' ? 'default' : 'outline'} size="icon-lg" aria-label="Ver em lista" aria-pressed={view === 'list'} onClick={() => changeView('list')}>
          <List className="size-4" />
        </Button>
        <Button variant={view === 'grid' ? 'default' : 'outline'} size="icon-lg" aria-label="Ver em cards" aria-pressed={view === 'grid'} onClick={() => changeView('grid')}>
          <LayoutGrid className="size-4" />
        </Button>
      </div>

      {query.isPending ? (
        <LoadingState />
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => query.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState title="Nenhuma obra encontrada" description="Ajuste a busca e os filtros, ou adicione uma obra." />
      ) : view === 'grid' ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
          {rows.map((p) => (
            <ProjectCard key={p.uuid} project={p} onOpen={() => navigate(`/obras/${p.uuid}`)} menu={<ActionMenu label={`Ações de ${p.name}`} items={menu(p)} />} />
          ))}
        </div>
      ) : (
        <div className="bg-card overflow-x-auto rounded-lg border">
          <table className="w-full text-sm">
            <caption className="sr-only">Obras</caption>
            <thead className="bg-muted/60 text-muted-foreground text-left text-xs">
              <tr>
                <th scope="col" className="px-3 py-2">Código</th>
                <th scope="col" className="px-3 py-2">Obra</th>
                <th scope="col" className="px-3 py-2">Cliente</th>
                <th scope="col" className="px-3 py-2">Status</th>
                <th scope="col" className="px-3 py-2">Execução</th>
                <th scope="col" className="px-3 py-2">Previsão</th>
                <th scope="col" className="w-14 px-3 py-2"><span className="sr-only">Ações</span></th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {rows.map((p) => (
                <tr key={p.uuid} className="hover:bg-primary-soft/40 cursor-pointer" onClick={() => navigate(`/obras/${p.uuid}`)}>
                  <td className="px-3 py-2 font-medium">{p.code}</td>
                  <td className="px-3 py-2">{p.name}</td>
                  <td className="px-3 py-2">{p.customer?.name ?? '—'}</td>
                  <td className="px-3 py-2"><StatusBadge tone={statusTone[p.status]}>{statusName(p.status)}</StatusBadge></td>
                  <td className="px-3 py-2"><ProgressIndicator value={p.progress} /></td>
                  <td className="px-3 py-2 tabular-nums">{formatDateOrDash(p.expected_end_date)}</td>
                  <td className="px-3 py-2" onClick={(e) => e.stopPropagation()}>
                    <ActionMenu label={`Ações de ${p.name}`} items={menu(p)} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {meta && meta.last_page > 1 && (
        <div className="mt-4 flex items-center justify-end gap-2 text-sm">
          <Button variant="outline" size="lg" disabled={page <= 1} onClick={() => setPage((n) => n - 1)}>
            Anterior
          </Button>
          <span className="text-muted-foreground tabular-nums">
            Página {meta.current_page} de {meta.last_page}
          </span>
          <Button variant="outline" size="lg" disabled={page >= meta.last_page} onClick={() => setPage((n) => n + 1)}>
            Próxima
          </Button>
        </div>
      )}

      {form && (
        <EntityFormModal
          title={form.row ? 'Editar obra' : 'Adicionar obra'}
          size="xl"
          mode={form.row ? 'edit' : 'create'}
          fields={projectFields}
          lookups={lookups}
          initial={form.row ? projectToForm(form.row) : {}}
          onClose={() => setForm(null)}
          onSubmit={async (b) => {
            if (form.row) return projectsApi.update(form.row.uuid, b)
            const created = await projectsApi.create(b)
            navigate(`/obras/${created.uuid}`)
          }}
          onSaved={() => {
            notify('success', form.row ? 'Obra atualizada.' : 'Obra criada.')
            setForm(null)
            refresh()
          }}
        />
      )}
      <ConfirmDialog
        open={removing !== null}
        title={`Excluir a obra ${removing?.code ?? ''}?`}
        description="A obra sai das listas. Obras com orçamentos ou outros vínculos não podem ser excluídas."
        confirmLabel="Excluir"
        destructive
        onConfirm={() =>
          removing &&
          projectsApi
            .remove(removing.uuid)
            .then(() => (notify('success', 'Obra excluída.'), setRemoving(null), refresh()))
            .catch((e) => (notify('error', getErrorMessage(e)), setRemoving(null)))
        }
        onCancel={() => setRemoving(null)}
      />
    </div>
  )
}

function FilterSelect({ label, value, options, onChange }: { label: string; value: string; options: { value: string; label: string }[]; onChange: (v: string) => void }) {
  return (
    <SelectInput className="w-44" aria-label={label} value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">{label}</option>
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </SelectInput>
  )
}

function Kpi({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="bg-card min-w-40 shrink-0 rounded-lg border px-4 py-3 shadow-(--shadow-card)" title={hint}>
      <p className="text-muted-foreground text-xs">{label}</p>
      <p className={cn('mt-1 text-lg font-semibold tabular-nums', hint && 'text-muted-foreground')}>{value}</p>
      {hint && <p className="text-muted-foreground text-[0.7rem]">{SOON}</p>}
    </div>
  )
}

function Person({ label, name }: { label: string; name?: string | null }) {
  return (
    <div className="min-w-0">
      <p className="text-muted-foreground text-xs">{label}</p>
      <p className={cn('flex items-center gap-1.5 truncate text-sm', !name && 'text-muted-foreground italic')}>
        <span className={cn('grid size-5 shrink-0 place-items-center rounded-full text-[0.65rem] font-semibold', name ? 'bg-primary text-primary-foreground' : 'bg-muted')} aria-hidden>
          {name ? name.charAt(0).toUpperCase() : ''}
        </span>
        <span className="truncate">{name ?? 'não atribuído'}</span>
      </p>
    </div>
  )
}

function Line({ label, value, soon }: { label: string; value?: string; soon?: boolean }) {
  return (
    <div className="flex items-center justify-between gap-2 text-sm">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className={cn('tabular-nums', soon && 'text-muted-foreground')} title={soon ? 'Disponível em uma próxima fase' : undefined}>
        {soon ? '—' : value}
      </dd>
    </div>
  )
}

function ProjectCard({ project: p, onOpen, menu }: { project: Project; onOpen: () => void; menu: React.ReactNode }) {
  return (
    <article className="bg-card flex flex-col rounded-lg border p-4 shadow-(--shadow-card)" aria-label={p.name}>
      <div className="mb-3 grid grid-cols-2 gap-2">
        <Person label="Responsável técnico" name={p.technical_manager?.name} />
        <Person label="Encarregado" name={p.foreman?.name} />
      </div>
      <p className="text-muted-foreground text-xs">{p.code}</p>
      <h2 className="mb-2 truncate text-base font-semibold" title={p.name}>
        {p.name}
      </h2>
      <div className="mb-1 flex items-center gap-2">
        <StatusBadge tone={statusTone[p.status]}>{statusName(p.status)}</StatusBadge>
      </div>
      <div className="bg-muted mt-1 h-2 overflow-hidden rounded-full" role="progressbar" aria-valuenow={p.progress} aria-valuemin={0} aria-valuemax={100} aria-label="Execução">
        <div className="bg-success h-full rounded-full" style={{ width: `${p.progress}%` }} />
      </div>
      <div className="text-muted-foreground mt-1 mb-3 flex justify-between text-xs tabular-nums">
        <span>{p.progress}% executada</span>
        <span>{p.elapsed_days ?? 0} dias decorridos</span>
      </div>
      <dl className="mb-4 space-y-1.5 border-t pt-3">
        <Line label="Saúde financeira" soon />
        <Line label="Valor apropriado" soon />
        <Line label="Previsão de entrega" value={formatDateOrDash(p.expected_end_date)} />
        <Line label="Atividades atrasadas" soon />
        <Line label="Efetivos na obra" value={String(p.active_members ?? 0)} />
      </dl>
      <div className="mt-auto flex gap-2">
        <Button variant="outline" size="lg" className="flex-1" onClick={onOpen}>
          Acessar
        </Button>
        {menu}
      </div>
    </article>
  )
}
