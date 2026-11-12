import { Download, FileSpreadsheet } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Bar, BarChart, CartesianGrid, Cell, ComposedChart, Legend, Line, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { useToast } from '@/app/providers/ToastProvider'
import { DataTable, Panel, type Column } from '@/components/data-display/DataTable'
import { DemoBanner, MetricCard, StatusBadge } from '@/components/data-display/indicators'
import { PageHeader } from '@/components/layout/PageHeader'
import { Tabs } from '@/components/layout/Tabs'
import { Button } from '@/components/ui/button'
import type { ResourceType } from '@/types'
import { formatCompactCurrency, formatCurrency, formatDecimal, formatPercent } from '@/utils/format'
import { useBudgetWorkspace } from './BudgetWorkspace'
import { abcCurve, budgetTotals, costByType, groupTotal, resourceTypeLabel, type AbcRow } from './calc'

const CHART = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)']
const axis = { fontSize: 12, fill: 'var(--muted-foreground)' }
const tabs = [
  { id: 'summary', label: 'Resumo financeiro' },
  { id: 'abc', label: 'Curva ABC' },
  { id: 'resources', label: 'Recursos' },
]

const classTone = { A: 'danger', B: 'warning', C: 'neutral' } as const

export function BudgetFinalizationPage() {
  const { groups, bdi, discount, compositions } = useBudgetWorkspace()
  const { notify } = useToast()
  const [tab, setTab] = useState('summary')

  const totals = budgetTotals(groups, compositions, bdi, discount)
  const abc = useMemo(() => abcCurve(groups, compositions), [groups, compositions])
  const byType = costByType(groups, compositions)
  const groupData = groups.map((g) => ({ name: g.name, value: groupTotal(g, compositions) }))
  const typeData = (Object.keys(byType) as ResourceType[]).filter((t) => byType[t] > 0).map((t) => ({ name: resourceTypeLabel[t], value: byType[t] }))

  const resources = useMemo(() => {
    const map = new Map<string, { key: string; description: string; type: ResourceType; unit: string; quantity: number; total: number }>()
    for (const item of groups.flatMap((g) => g.items)) {
      const comp = item.compositionId ? compositions.find((c) => c.id === item.compositionId) : undefined
      const lines = comp
        ? comp.inputs.map((i) => ({ key: i.id, description: i.description, type: i.type, unit: i.unit, quantity: item.quantity * i.coefficient, total: item.quantity * i.coefficient * i.unitPrice }))
        : [{ key: item.id, description: item.description, type: item.type, unit: item.unit, quantity: item.quantity, total: item.quantity * item.unitPrice }]
      for (const l of lines) {
        const k = `${l.description}|${l.unit}`
        const cur = map.get(k)
        map.set(k, cur ? { ...cur, quantity: cur.quantity + l.quantity, total: cur.total + l.total } : l)
      }
    }
    return [...map.values()].sort((a, b) => b.total - a.total)
  }, [groups, compositions])

  const abcColumns: Column<AbcRow>[] = [
    { key: 'code', header: 'Código', cell: (r) => r.item.code },
    { key: 'desc', header: 'Descrição', cell: (r) => r.item.description },
    { key: 'total', header: 'Total', align: 'right', cell: (r) => formatCurrency(r.total), sortValue: (r) => r.total },
    { key: 'share', header: '% item', align: 'right', cell: (r) => formatPercent(r.share) },
    { key: 'cum', header: '% acumulado', align: 'right', cell: (r) => formatPercent(r.cumulative) },
    { key: 'class', header: 'Classe', cell: (r) => <StatusBadge tone={classTone[r.klass]}>{r.klass}</StatusBadge> },
  ]
  const resColumns: Column<(typeof resources)[number]>[] = [
    { key: 'desc', header: 'Recurso', cell: (r) => r.description },
    { key: 'type', header: 'Tipo', cell: (r) => <StatusBadge tone="primary">{resourceTypeLabel[r.type]}</StatusBadge> },
    { key: 'unit', header: 'Un.', cell: (r) => r.unit },
    { key: 'qty', header: 'Quantidade', align: 'right', cell: (r) => formatDecimal(r.quantity), sortValue: (r) => r.quantity },
    { key: 'total', header: 'Custo total', align: 'right', cell: (r) => formatCurrency(r.total), sortValue: (r) => r.total },
  ]

  const exportDemo = (what: string) => notify('info', `Exportação de ${what} ainda não implementada`, 'Esta ação será habilitada em etapa futura.')

  return (
    <>
      <PageHeader
        title="Resumo e finalização"
        description="Visão consolidada do orçamento: custos, curva ABC e recursos."
        actions={
          <>
            <Button variant="outline" onClick={() => exportDemo('PDF')}><Download className="size-4" /> Exportar PDF</Button>
            <Button variant="outline" onClick={() => exportDemo('planilha')}><FileSpreadsheet className="size-4" /> Exportar planilha</Button>
          </>
        }
      />
      <DemoBanner>Valores calculados localmente com dados fictícios — não são oficiais nem utilizáveis como proposta.</DemoBanner>

      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard label="Custo direto" value={formatCurrency(totals.direct)} />
        <MetricCard label={`BDI (${formatDecimal(bdi)}%)`} value={formatCurrency(totals.bdiValue)} />
        <MetricCard label={`Desconto (${formatDecimal(discount)}%)`} value={formatCurrency(totals.discountValue)} />
        <MetricCard label="Custo indireto" value="A definir" />
        <MetricCard label="Valor total" value={formatCurrency(totals.total)} emphasis />
      </div>
      <p className="text-muted-foreground -mt-2 mb-4 text-xs">
        O custo indireto depende da metodologia que será definida pelo negócio; nesta demonstração apenas o BDI é aplicado.
      </p>

      <Tabs tabs={tabs} active={tab} onChange={setTab} label="Seções da finalização">
        {tab === 'summary' && (
          <div className="grid gap-4 lg:grid-cols-2">
            <Panel title="Distribuição por etapa">
              <div className="h-72 p-3" role="img" aria-label="Gráfico de barras: custo por etapa">
                <ResponsiveContainer>
                  <BarChart data={groupData} layout="vertical" margin={{ left: 24 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                    <XAxis type="number" tick={axis} tickFormatter={(v: number) => formatCompactCurrency(v)} />
                    <YAxis type="category" dataKey="name" tick={axis} width={120} />
                    <Tooltip formatter={(v) => formatCurrency(Number(v))} />
                    <Bar dataKey="value" name="Custo" fill="var(--chart-1)" radius={[0, 4, 4, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </Panel>
            <Panel title="Composição dos custos por tipo de recurso">
              <div className="h-72 p-3" role="img" aria-label="Gráfico de pizza: custo por tipo de recurso">
                <ResponsiveContainer>
                  <PieChart>
                    <Pie data={typeData} dataKey="value" nameKey="name" innerRadius={55} outerRadius={95} paddingAngle={2}>
                      {typeData.map((_, i) => <Cell key={i} fill={CHART[i % CHART.length]} />)}
                    </Pie>
                    <Tooltip formatter={(v) => formatCurrency(Number(v))} />
                    <Legend />
                  </PieChart>
                </ResponsiveContainer>
              </div>
            </Panel>
          </div>
        )}

        {tab === 'abc' && (
          <div className="flex flex-col gap-4">
            <Panel title="Diagrama de Pareto">
              <div className="h-72 p-3" role="img" aria-label="Gráfico de Pareto: custo por item e percentual acumulado">
                <ResponsiveContainer>
                  <ComposedChart data={abc.map((r) => ({ name: r.item.code, total: r.total, cumulative: r.cumulative * 100 }))}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                    <XAxis dataKey="name" tick={axis} />
                    <YAxis yAxisId="l" tick={axis} tickFormatter={(v: number) => formatCompactCurrency(v)} />
                    <YAxis yAxisId="r" orientation="right" tick={axis} domain={[0, 100]} unit="%" />
                    <Tooltip />
                    <Legend />
                    <Bar yAxisId="l" dataKey="total" name="Custo" fill="var(--chart-1)" radius={[4, 4, 0, 0]} />
                    <Line yAxisId="r" dataKey="cumulative" name="% acumulado" stroke="var(--chart-5)" strokeWidth={2} />
                  </ComposedChart>
                </ResponsiveContainer>
              </div>
            </Panel>
            <Panel title="Curva ABC de insumos e serviços">
              <DataTable caption="Curva ABC" columns={abcColumns} rows={abc} rowKey={(r) => r.item.id} pageSize={10} />
            </Panel>
          </div>
        )}

        {tab === 'resources' && (
          <Panel title="Visualização de recursos (insumos consolidados)">
            <DataTable caption="Recursos consolidados" columns={resColumns} rows={resources} rowKey={(r) => r.key} pageSize={10} />
          </Panel>
        )}
      </Tabs>
    </>
  )
}
