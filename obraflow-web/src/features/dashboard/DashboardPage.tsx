import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, ArrowDownCircle, ArrowUpCircle, Calculator, CheckCircle2, Clock, HardHat, Hourglass, PiggyBank, Wallet } from 'lucide-react'
import { Bar, BarChart, CartesianGrid, Cell, Legend, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { DemoBanner, ProgressIndicator, StatCard, StatusBadge } from '@/components/data-display/indicators'
import { Panel } from '@/components/data-display/DataTable'
import { QueryBoundary } from '@/components/feedback/QueryBoundary'
import { PageHeader } from '@/components/layout/PageHeader'
import { projectStatusMeta } from '@/features/shared'
import { dashboardService } from '@/services'
import { formatCompactCurrency, formatDate } from '@/utils/format'

const CHART = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)']
const axis = { fontSize: 12, fill: 'var(--muted-foreground)' }

export function DashboardPage() {
  const query = useQuery({ queryKey: ['dashboard'], queryFn: dashboardService.get })

  return (
    <>
      <PageHeader title="Visão geral" description="Indicadores executivos das obras, orçamentos e finanças." />
      <DemoBanner />
      <QueryBoundary query={query}>
        {(d) => {
          const k = d.kpis
          return (
            <div className="flex flex-col gap-4">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <StatCard label="Total de obras" value={String(k.totalProjects)} icon={HardHat} />
                <StatCard label="Em andamento" value={String(k.inProgress)} icon={Clock} />
                <StatCard label="Concluídas" value={String(k.completed)} icon={CheckCircle2} />
                <StatCard label="Em atraso" value={String(k.delayed)} icon={AlertTriangle} trend={{ value: '1 obra', positive: false }} />
                <StatCard label="Solicitações pendentes" value={String(k.pendingRequests)} icon={Hourglass} />
                <StatCard label="Orçamento total" value={formatCompactCurrency(k.totalBudget)} icon={Calculator} />
                <StatCard label="Custo realizado" value={formatCompactCurrency(k.realizedCost)} icon={Wallet} hint="37% do orçado" />
                <StatCard label="Saldo orçamentário" value={formatCompactCurrency(k.totalBudget - k.realizedCost)} icon={PiggyBank} />
                <StatCard label="Contas a pagar" value={formatCompactCurrency(k.payable)} icon={ArrowDownCircle} />
                <StatCard label="Contas a receber" value={formatCompactCurrency(k.receivable)} icon={ArrowUpCircle} />
              </div>

              <div className="grid gap-4 lg:grid-cols-2">
                <Panel title="Evolução financeira (R$ milhões)">
                  <div className="h-72 p-3" role="img" aria-label="Gráfico de linhas: previsto versus realizado por mês">
                    <ResponsiveContainer>
                      <LineChart data={d.financialEvolution}>
                        <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                        <XAxis dataKey="month" tick={axis} />
                        <YAxis tick={axis} />
                        <Tooltip />
                        <Legend />
                        <Line type="monotone" dataKey="planned" name="Previsto" stroke="var(--chart-3)" strokeWidth={2} dot={false} />
                        <Line type="monotone" dataKey="realized" name="Realizado" stroke="var(--chart-1)" strokeWidth={2.5} />
                      </LineChart>
                    </ResponsiveContainer>
                  </div>
                </Panel>
                <Panel title="Orçamento × realizado por obra (R$ milhões)">
                  <div className="h-72 p-3" role="img" aria-label="Gráfico de barras: orçamento versus realizado">
                    <ResponsiveContainer>
                      <BarChart data={d.budgetVsActual}>
                        <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                        <XAxis dataKey="project" tick={axis} />
                        <YAxis tick={axis} />
                        <Tooltip />
                        <Legend />
                        <Bar dataKey="budget" name="Orçado" fill="var(--chart-4)" radius={[4, 4, 0, 0]} />
                        <Bar dataKey="actual" name="Realizado" fill="var(--chart-1)" radius={[4, 4, 0, 0]} />
                      </BarChart>
                    </ResponsiveContainer>
                  </div>
                </Panel>
              </div>

              <div className="grid gap-4 lg:grid-cols-3">
                <Panel title="Obras por situação">
                  <div className="h-64 p-3" role="img" aria-label="Gráfico de pizza: obras por situação">
                    <ResponsiveContainer>
                      <PieChart>
                        <Pie data={d.byStatus} dataKey="value" nameKey="name" innerRadius={50} outerRadius={85} paddingAngle={2}>
                          {d.byStatus.map((_, i) => <Cell key={i} fill={CHART[i % CHART.length]} />)}
                        </Pie>
                        <Tooltip />
                        <Legend />
                      </PieChart>
                    </ResponsiveContainer>
                  </div>
                </Panel>
                <Panel title="Desempenho mensal (% avanço físico)">
                  <div className="h-64 p-3" role="img" aria-label="Gráfico de barras: avanço físico mensal">
                    <ResponsiveContainer>
                      <BarChart data={d.monthlyPerformance}>
                        <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                        <XAxis dataKey="month" tick={axis} />
                        <YAxis tick={axis} />
                        <Tooltip />
                        <Bar dataKey="progress" name="Avanço (%)" fill="var(--chart-2)" radius={[4, 4, 0, 0]} />
                      </BarChart>
                    </ResponsiveContainer>
                  </div>
                </Panel>
                <Panel title="Distribuição de custos (%)">
                  <div className="h-64 p-3" role="img" aria-label="Gráfico de pizza: distribuição de custos">
                    <ResponsiveContainer>
                      <PieChart>
                        <Pie data={d.costDistribution} dataKey="value" nameKey="name" outerRadius={85}>
                          {d.costDistribution.map((_, i) => <Cell key={i} fill={CHART[i % CHART.length]} />)}
                        </Pie>
                        <Tooltip />
                        <Legend />
                      </PieChart>
                    </ResponsiveContainer>
                  </div>
                </Panel>
              </div>

              <div className="grid gap-4 lg:grid-cols-2">
                <Panel title="Obras recentes">
                  <ul className="divide-y">
                    {d.recentProjects.map((p) => (
                      <li key={p.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div>
                          <p className="text-sm font-medium">{p.name}</p>
                          <p className="text-muted-foreground text-xs">{p.code}</p>
                        </div>
                        <div className="flex items-center gap-3">
                          <ProgressIndicator value={p.progress} label={`Progresso de ${p.name}`} />
                          <StatusBadge tone={projectStatusMeta[p.status].tone}>{projectStatusMeta[p.status].label}</StatusBadge>
                        </div>
                      </li>
                    ))}
                  </ul>
                </Panel>
                <div className="flex flex-col gap-4">
                  <Panel title="Alertas">
                    <ul className="divide-y">
                      {d.alerts.map((a) => (
                        <li key={a.id} className="flex items-start gap-3 px-4 py-3 text-sm">
                          <StatusBadge tone={a.tone === 'danger' ? 'danger' : a.tone === 'warning' ? 'warning' : 'primary'}>
                            {a.tone === 'danger' ? 'Crítico' : a.tone === 'warning' ? 'Atenção' : 'Info'}
                          </StatusBadge>
                          <span>{a.text}</span>
                        </li>
                      ))}
                    </ul>
                  </Panel>
                  <Panel title="Atividades pendentes">
                    <ul className="divide-y">
                      {d.pendingTasks.map((t) => (
                        <li key={t.id} className="flex items-center justify-between gap-2 px-4 py-2.5 text-sm">
                          <span>{t.title}</span>
                          <span className="text-muted-foreground text-xs whitespace-nowrap">até {formatDate(t.due)}</span>
                        </li>
                      ))}
                    </ul>
                  </Panel>
                </div>
              </div>

              <Panel title="Últimas movimentações">
                <ul className="divide-y">
                  {d.movements.map((m) => (
                    <li key={m.id} className="flex items-center justify-between gap-2 px-4 py-2.5 text-sm">
                      <span>{m.text}</span>
                      <span className="text-muted-foreground text-xs whitespace-nowrap">{formatDate(m.date)}</span>
                    </li>
                  ))}
                </ul>
              </Panel>
            </div>
          )
        }}
      </QueryBoundary>
    </>
  )
}
