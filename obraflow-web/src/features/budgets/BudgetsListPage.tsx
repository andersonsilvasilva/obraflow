import { useQuery } from '@tanstack/react-query'
import { Plus, Sparkles } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { DataTable, Panel, TableToolbar, type Column } from '@/components/data-display/DataTable'
import { DemoBanner, StatusBadge } from '@/components/data-display/indicators'
import { QueryBoundary } from '@/components/feedback/QueryBoundary'
import { FormField, SearchInput, SelectInput } from '@/components/forms/fields'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { budgetStatusMeta } from '@/features/shared'
import { budgetsService } from '@/services'
import type { Budget } from '@/types'
import { formatCurrency, formatDate } from '@/utils/format'

export function BudgetsListPage() {
  const query = useQuery({ queryKey: ['budgets'], queryFn: budgetsService.list })
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')

  const columns: Column<Budget>[] = [
    { key: 'code', header: 'Código', cell: (b) => <span className="font-medium">{b.code}</span>, sortValue: (b) => b.code },
    { key: 'desc', header: 'Descrição', cell: (b) => b.description, className: 'max-w-72 truncate' },
    { key: 'customer', header: 'Cliente', cell: (b) => b.customer },
    { key: 'project', header: 'Obra', cell: (b) => b.project },
    { key: 'owner', header: 'Responsável', cell: (b) => b.owner },
    { key: 'date', header: 'Data', cell: (b) => formatDate(b.date), sortValue: (b) => b.date },
    { key: 'total', header: 'Valor total', align: 'right', cell: (b) => formatCurrency(b.total), sortValue: (b) => b.total },
    { key: 'status', header: 'Situação', cell: (b) => <StatusBadge tone={budgetStatusMeta[b.status].tone}>{budgetStatusMeta[b.status].label}</StatusBadge> },
    {
      key: 'actions',
      header: 'Ações',
      cell: () => (
        <div className="flex gap-1">
          <Button variant="ghost" size="sm" onClick={(e) => { e.stopPropagation(); navigate('/orcamentos/b1/preencher') }}>Abrir</Button>
          <Button variant="ghost" size="sm" onClick={(e) => { e.stopPropagation(); navigate('/orcamentos/b1/finalizacao') }}>Resumo</Button>
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Orçamentos"
        description="Elabore, revise e acompanhe os orçamentos de obras."
        actions={
          <>
            <Button variant="outline" onClick={() => navigate('/orcamentos/inteligente')}><Sparkles className="size-4" /> Orçamento inteligente</Button>
            <Button onClick={() => navigate('/orcamentos/novo')}><Plus className="size-4" /> Novo orçamento</Button>
          </>
        }
      />
      <DemoBanner>
        Nesta demonstração, todo orçamento aberto carrega o mesmo conjunto de itens fictícios. Veja também{' '}
        <Link to="/orcamentos/b1/preencher" className="text-primary underline">o preenchimento</Link>.
      </DemoBanner>
      <QueryBoundary query={query}>
        {(data) => {
          const term = search.trim().toLowerCase()
          const rows = data.filter(
            (b) => (!term || b.code.toLowerCase().includes(term) || b.description.toLowerCase().includes(term) || b.customer.toLowerCase().includes(term)) && (!status || b.status === status),
          )
          return (
            <Panel>
              <TableToolbar>
                <SearchInput className="w-72" placeholder="Pesquisar orçamento…" aria-label="Pesquisar orçamentos" value={search} onChange={(e) => setSearch(e.target.value)} />
                <FormField label="Situação" className="w-44">
                  <SelectInput value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">Todas</option>
                    {Object.entries(budgetStatusMeta).map(([k, m]) => <option key={k} value={k}>{m.label}</option>)}
                  </SelectInput>
                </FormField>
              </TableToolbar>
              <DataTable caption="Lista de orçamentos" columns={columns} rows={rows} rowKey={(b) => b.id} onRowClick={() => navigate('/orcamentos/b1/preencher')} />
            </Panel>
          )
        }}
      </QueryBoundary>
    </>
  )
}
