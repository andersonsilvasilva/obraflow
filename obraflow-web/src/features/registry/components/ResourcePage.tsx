import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Pencil, Plus, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useToast } from '@/app/providers/ToastProvider'
import { Panel } from '@/components/data-display/DataTable'
import { ConfirmDialog } from '@/components/feedback/Modal'
import { EmptyState, ErrorState, LoadingState } from '@/components/feedback/states'
import { SearchInput, SelectInput } from '@/components/forms/fields'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-context'
import { getErrorMessage } from '@/lib/api-error'
import { cn } from '@/lib/utils'
import type { Option, Page } from '../api'
import { EntityFormModal, type FieldDef } from './EntityFormModal'

export interface Column<T> {
  key: string
  header: string
  cell: (row: T) => ReactNode
  /** Presença habilita ordenação (na página atual) — padrão L3. */
  sortValue?: (row: T) => string | number
  align?: 'left' | 'right'
  className?: string
}

export interface FilterDef {
  name: string
  placeholder: string
  options: Option[] | string
}

export interface ListParams {
  page: number
  per_page: number
  q: string
  [filter: string]: string | number
}

export interface ResourceConfig<T> {
  title: string
  description?: string
  /** Nome no singular, minúsculo (mensagens): "empresa" */
  singular: string
  queryKey: string
  managePermission: string
  fetch: (params: ListParams) => Promise<Page<T>>
  rowKey: (row: T) => string
  columns: Column<T>[]
  filters?: FilterDef[]
  searchPlaceholder?: string
  fields: FieldDef[]
  /** Opções dinâmicas (selects/filtros), por chave. Hook chamado uma vez por render. */
  useLookups?: () => Record<string, Option[]>
  toForm: (row: T) => Record<string, string>
  create: (body: Record<string, unknown>) => Promise<unknown>
  update: (row: T, body: Record<string, unknown>) => Promise<unknown>
  remove?: (row: T) => Promise<unknown>
  /** Ações extras por linha (ex.: ícone de pasta), antes de editar/excluir — padrão L4. */
  rowActions?: (row: T) => ReactNode
  /** Permissão por linha (obras: nível de acesso); padrão = managePermission. */
  canEditRow?: (row: T) => boolean
  /** Clique na linha abre o detalhe (obras). */
  onOpen?: (row: T) => void
  formSize?: 'md' | 'lg' | 'xl'
  /** Chaves de cache a invalidar além da própria (ex.: listas usadas em selects). */
  invalidates?: string[]
  /** Botões extras na barra (L1), antes de "Adicionar" — ex.: importar planilha. */
  toolbarExtra?: (ctx: { canManage: boolean; refresh: () => void }) => ReactNode
}

const PAGE_SIZES = [10, 25, 50, 100]

/** Listagem padrão dos cadastros (L1–L6) com criação/edição em modal (F1) e exclusão com confirmação. */
export function ResourcePage<T>({ config }: { config: ResourceConfig<T> }) {
  const { can } = useAuth()
  const { notify } = useToast()
  const client = useQueryClient()
  const canManage = can(config.managePermission)
  const lookups = config.useLookups?.() ?? {}

  const [search, setSearch] = useState('')
  const [q, setQ] = useState('')
  const [filters, setFilters] = useState<Record<string, string>>({})
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(10)
  const [sort, setSort] = useState<{ key: string; dir: 'asc' | 'desc' } | null>(null)
  const [editing, setEditing] = useState<T | 'new' | null>(null)
  const [deleting, setDeleting] = useState<T | null>(null)

  // busca com atraso curto (evita uma requisição por tecla)
  useEffect(() => {
    const t = setTimeout(() => {
      setQ(search.trim())
      setPage(1)
    }, 300)
    return () => clearTimeout(t)
  }, [search])

  const params: ListParams = { page, per_page: perPage, q, ...filters }
  const query = useQuery({
    queryKey: [config.queryKey, params],
    queryFn: () => config.fetch(params),
    placeholderData: keepPreviousData,
  })

  const invalidate = () => {
    for (const key of [config.queryKey, ...(config.invalidates ?? [])]) client.invalidateQueries({ queryKey: [key] })
  }

  const removal = useMutation({
    mutationFn: (row: T) => config.remove!(row),
    onSuccess: () => {
      notify('success', `Registro excluído`)
      invalidate()
    },
    onError: (e) => notify('error', 'Não foi possível excluir', getErrorMessage(e)),
    onSettled: () => setDeleting(null),
  })

  const rows = useMemo(() => {
    const data = query.data?.data ?? []
    const col = config.columns.find((c) => c.key === sort?.key)
    if (!sort || !col?.sortValue) return data
    const get = col.sortValue
    const f = sort.dir === 'asc' ? 1 : -1
    return [...data].sort((a, b) => (get(a) < get(b) ? -1 : get(a) > get(b) ? 1 : 0) * f)
  }, [query.data, sort, config.columns])

  const optionsOf = (o: Option[] | string) => (typeof o === 'string' ? (lookups[o] ?? []) : o)
  const meta = query.data?.meta

  return (
    <div>
      <PageHeader title={config.title} description={config.description} />

      <Panel>
        {/* L1: busca e filtros à esquerda; "Adicionar" à direita */}
        <div className="flex flex-wrap items-center gap-2 border-b p-3">
          <SearchInput
            className="w-full sm:w-64"
            placeholder={config.searchPlaceholder ?? 'Buscar por nome'}
            aria-label={config.searchPlaceholder ?? 'Buscar por nome'}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          {config.filters?.map((f) => (
            <SelectInput
              key={f.name}
              aria-label={f.placeholder}
              className="w-full sm:w-48"
              value={filters[f.name] ?? ''}
              onChange={(e) => {
                setFilters((cur) => ({ ...cur, [f.name]: e.target.value }))
                setPage(1)
              }}
            >
              <option value="">{f.placeholder}</option>
              {optionsOf(f.options).map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </SelectInput>
          ))}
          <div className="ml-auto flex gap-2">
            {config.toolbarExtra?.({ canManage, refresh: invalidate })}
            <Button size="lg" onClick={() => setEditing('new')} disabled={!canManage} title={canManage ? undefined : 'Sem permissão para cadastrar'}>
              <Plus className="size-4" /> Adicionar
            </Button>
          </div>
        </div>

        {query.isPending ? (
          <LoadingState />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => query.refetch()} />
        ) : rows.length === 0 ? (
          <EmptyState
            title={q || Object.values(filters).some(Boolean) ? 'Nada encontrado' : `Nenhum registro de ${config.singular}`}
            description={q || Object.values(filters).some(Boolean) ? 'Ajuste a busca ou os filtros.' : 'Use "Adicionar" para criar o primeiro.'}
            action={
              canManage && !q ? (
                <Button onClick={() => setEditing('new')}>
                  <Plus className="size-4" /> Adicionar
                </Button>
              ) : undefined
            }
          />
        ) : (
          <>
            {/* L3/L6: tabela com cabeçalho fixo e rolagem dentro do cartão */}
            <div className="max-h-[calc(100dvh-16rem)] overflow-auto">
              <table className="w-full min-w-max text-sm">
                <caption className="sr-only">{config.title}</caption>
                <thead className="bg-muted sticky top-0 z-[1]">
                  <tr className="border-b text-left">
                    {config.columns.map((c) => {
                      const active = sort?.key === c.key
                      return (
                        <th
                          key={c.key}
                          scope="col"
                          aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : undefined}
                          className={cn('px-3 py-2.5 font-semibold', c.align === 'right' && 'text-right', c.className)}
                        >
                          {c.sortValue ? (
                            <button
                              type="button"
                              className="hover:text-primary inline-flex items-center gap-1"
                              onClick={() => setSort(active && sort.dir === 'asc' ? { key: c.key, dir: 'desc' } : { key: c.key, dir: 'asc' })}
                            >
                              {c.header}
                              {active ? (
                                sort.dir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />
                              ) : (
                                <span className="text-muted-foreground flex flex-col leading-none" aria-hidden>
                                  <ArrowUp className="-mb-1 size-2.5" />
                                  <ArrowDown className="size-2.5" />
                                </span>
                              )}
                            </button>
                          ) : (
                            c.header
                          )}
                        </th>
                      )
                    })}
                    <th scope="col" className="w-px px-3 py-2.5">
                      <span className="sr-only">Ações</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr
                      key={config.rowKey(row)}
                      className={cn('hover:bg-primary-soft/40 border-b last:border-0', config.onOpen && 'cursor-pointer')}
                      onClick={config.onOpen ? () => config.onOpen!(row) : undefined}
                    >
                      {config.columns.map((c) => (
                        <td key={c.key} className={cn('px-3 py-2.5', c.align === 'right' && 'text-right tabular-nums', c.className)}>
                          {c.cell(row)}
                        </td>
                      ))}
                      {/* L4: ações por linha — extras (pasta), editar, excluir */}
                      <td className="px-3 py-1.5" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-end gap-1">
                          {config.rowActions?.(row)}
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Editar"
                            title={(config.canEditRow?.(row) ?? canManage) ? 'Editar' : 'Sem permissão para editar'}
                            disabled={!(config.canEditRow?.(row) ?? canManage)}
                            onClick={() => setEditing(row)}
                          >
                            <Pencil className="size-4" />
                          </Button>
                          {config.remove && (
                            <Button
                              variant="ghost"
                              size="icon"
                              aria-label="Excluir"
                              title={canManage ? 'Excluir' : 'Sem permissão para excluir'}
                              disabled={!canManage}
                              onClick={() => setDeleting(row)}
                            >
                              <Trash2 className="size-4" />
                            </Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {meta && (
              <Paginator
                page={meta.current_page}
                lastPage={meta.last_page}
                total={meta.total}
                perPage={perPage}
                onPage={setPage}
                onPerPage={(n) => {
                  setPerPage(n)
                  setPage(1)
                }}
              />
            )}
          </>
        )}
      </Panel>

      {editing !== null && (
        <EntityFormModal
          title={editing === 'new' ? `Adicionar ${config.singular}` : `Editar ${config.singular}`}
          fields={config.fields}
          lookups={lookups}
          mode={editing === 'new' ? 'create' : 'edit'}
          initial={editing === 'new' ? {} : config.toForm(editing)}
          size={config.formSize}
          onClose={() => setEditing(null)}
          onSubmit={(body) => (editing === 'new' ? config.create(body) : config.update(editing, body))}
          onSaved={() => {
            notify('success', editing === 'new' ? 'Cadastro criado' : 'Alterações salvas')
            setEditing(null)
            invalidate()
          }}
        />
      )}

      <ConfirmDialog
        open={deleting !== null}
        title={`Excluir ${config.singular}?`}
        description="Esta ação não pode ser desfeita pela tela. Registros em uso não podem ser excluídos."
        confirmLabel="Excluir"
        destructive
        onCancel={() => setDeleting(null)}
        onConfirm={() => deleting && removal.mutate(deleting)}
      />
    </div>
  )
}

/** L5: anterior/próxima, números de página e "N / Página". */
export function Paginator({
  page,
  lastPage,
  total,
  perPage,
  onPage,
  onPerPage,
}: {
  page: number
  lastPage: number
  total: number
  perPage: number
  onPage: (p: number) => void
  onPerPage: (n: number) => void
}) {
  const pages = useMemo(() => {
    const start = Math.max(1, Math.min(page - 2, lastPage - 4))
    return Array.from({ length: Math.min(5, lastPage) }, (_, i) => start + i)
  }, [page, lastPage])

  return (
    <nav aria-label="Paginação" className="flex flex-wrap items-center justify-end gap-1 border-t px-3 py-2.5 text-sm">
      <span className="text-muted-foreground mr-auto">{total} registro(s)</span>
      <Button variant="ghost" size="icon" disabled={page <= 1} onClick={() => onPage(page - 1)} aria-label="Página anterior">
        <ChevronLeft className="size-4" />
      </Button>
      {pages.map((p) => (
        <Button
          key={p}
          variant={p === page ? 'outline' : 'ghost'}
          size="icon"
          aria-current={p === page ? 'page' : undefined}
          className={cn(p === page && 'border-primary text-primary')}
          onClick={() => onPage(p)}
        >
          {p}
        </Button>
      ))}
      <Button variant="ghost" size="icon" disabled={page >= lastPage} onClick={() => onPage(page + 1)} aria-label="Próxima página">
        <ChevronRight className="size-4" />
      </Button>
      <SelectInput aria-label="Registros por página" className="ml-2 w-28" value={perPage} onChange={(e) => onPerPage(Number(e.target.value))}>
        {PAGE_SIZES.map((n) => (
          <option key={n} value={n}>
            {n} / Página
          </option>
        ))}
      </SelectInput>
    </nav>
  )
}

