import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight } from 'lucide-react'
import { useMemo, useState, type ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { EmptyState } from '@/components/feedback/states'
import { cn } from '@/lib/utils'

export interface Column<T> {
  key: string
  header: string
  cell: (row: T) => ReactNode
  align?: 'left' | 'right'
  /** Presença habilita ordenação por esta coluna. */
  sortValue?: (row: T) => string | number
  className?: string
}

interface DataTableProps<T> {
  columns: Column<T>[]
  rows: T[]
  rowKey: (row: T) => string
  pageSize?: number
  caption: string
  emptyTitle?: string
  emptyDescription?: string
  onRowClick?: (row: T) => void
}

export function DataTable<T>({
  columns,
  rows,
  rowKey,
  pageSize = 10,
  caption,
  emptyTitle = 'Nenhum registro encontrado',
  emptyDescription = 'Ajuste os filtros ou cadastre um novo registro.',
  onRowClick,
}: DataTableProps<T>) {
  const [sort, setSort] = useState<{ key: string; dir: 'asc' | 'desc' } | null>(null)
  const [page, setPage] = useState(1)

  const sorted = useMemo(() => {
    const col = columns.find((c) => c.key === sort?.key)
    if (!sort || !col?.sortValue) return rows
    const get = col.sortValue
    const factor = sort.dir === 'asc' ? 1 : -1
    return [...rows].sort((a, b) => {
      const va = get(a)
      const vb = get(b)
      return (va < vb ? -1 : va > vb ? 1 : 0) * factor
    })
  }, [rows, columns, sort])

  const totalPages = Math.max(1, Math.ceil(sorted.length / pageSize))
  const current = Math.min(page, totalPages)
  const visible = sorted.slice((current - 1) * pageSize, current * pageSize)

  if (rows.length === 0) return <EmptyState title={emptyTitle} description={emptyDescription} />

  return (
    <div>
      <div className="overflow-x-auto">
        <table className="w-full min-w-max text-sm">
          <caption className="sr-only">{caption}</caption>
          <thead>
            <tr className="bg-muted/60 text-muted-foreground border-b text-xs uppercase">
              {columns.map((c) => {
                const active = sort?.key === c.key
                return (
                  <th
                    key={c.key}
                    scope="col"
                    aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : undefined}
                    className={cn('px-3 py-2.5 font-medium', c.align === 'right' ? 'text-right' : 'text-left', c.className)}
                  >
                    {c.sortValue ? (
                      <button
                        type="button"
                        className="hover:text-foreground inline-flex items-center gap-1 uppercase"
                        onClick={() => setSort(active && sort.dir === 'asc' ? { key: c.key, dir: 'desc' } : { key: c.key, dir: 'asc' })}
                      >
                        {c.header}
                        {active && (sort.dir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />)}
                      </button>
                    ) : (
                      c.header
                    )}
                  </th>
                )
              })}
            </tr>
          </thead>
          <tbody>
            {visible.map((row) => (
              <tr
                key={rowKey(row)}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
                className={cn('hover:bg-primary-soft/50 border-b last:border-0', onRowClick && 'cursor-pointer')}
              >
                {columns.map((c) => (
                  <td key={c.key} className={cn('px-3 py-2.5', c.align === 'right' && 'text-right tabular-nums', c.className)}>
                    {c.cell(row)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <TablePagination page={current} totalPages={totalPages} total={sorted.length} pageSize={pageSize} onPageChange={setPage} />
    </div>
  )
}

export function TablePagination({
  page,
  totalPages,
  total,
  pageSize,
  onPageChange,
}: {
  page: number
  totalPages: number
  total: number
  pageSize: number
  onPageChange: (page: number) => void
}) {
  const from = total === 0 ? 0 : (page - 1) * pageSize + 1
  const to = Math.min(page * pageSize, total)
  return (
    <nav aria-label="Paginação" className="flex items-center justify-between gap-2 border-t px-3 py-2.5 text-sm">
      <span className="text-muted-foreground">
        {from}–{to} de {total}
      </span>
      <div className="flex items-center gap-1">
        <Button variant="outline" size="icon" disabled={page <= 1} onClick={() => onPageChange(page - 1)} aria-label="Página anterior">
          <ChevronLeft className="size-4" />
        </Button>
        <span className="px-2 tabular-nums">
          {page} / {totalPages}
        </span>
        <Button variant="outline" size="icon" disabled={page >= totalPages} onClick={() => onPageChange(page + 1)} aria-label="Próxima página">
          <ChevronRight className="size-4" />
        </Button>
      </div>
    </nav>
  )
}

/** Barra de filtros acima da tabela. */
export function TableToolbar({ children }: { children: ReactNode }) {
  return <div className="flex flex-wrap items-end gap-3 border-b p-3">{children}</div>
}

/** Cartão padrão que envolve tabelas e blocos de conteúdo. */
export function Panel({ title, actions, children, className }: { title?: string; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={cn('bg-card shadow-card rounded-xl border', className)}>
      {(title || actions) && (
        <header className="flex items-center justify-between gap-2 border-b px-4 py-3">
          {title && <h2 className="text-sm font-semibold">{title}</h2>}
          {actions}
        </header>
      )}
      {children}
    </section>
  )
}
