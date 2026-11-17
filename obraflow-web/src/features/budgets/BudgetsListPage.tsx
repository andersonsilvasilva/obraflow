import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { StatusBadge } from '@/components/data-display/indicators'
import { useAuth } from '@/features/auth/auth-context'
import type { FieldDef } from '@/features/registry/components/EntityFormModal'
import { ResourcePage, type ResourceConfig } from '@/features/registry/components/ResourcePage'
import { useCompanyOptions, useCustomerOptions } from '@/features/registry/lookups'
import { http } from '@/lib/http'
import { formatCurrency } from '@/utils/format'
import { budgetsApi, budgetStatusLabel, budgetStatusOptions, budgetStatusTone, modeOptions, regimeLabel, type Budget } from './api'

const fields: FieldDef[] = [
  { name: 'company_uuid', label: 'Empresa', type: 'select', options: 'companies', required: true, createOnly: true, full: true },
  {
    name: 'code',
    label: 'Código',
    placeholder: `Automático: ORC-001/${new Date().getFullYear()}`,
    help: 'Deixe em branco para gerar automaticamente no formato ORC-NNN/AAAA: numeração sequencial que recomeça a cada ano, usando o ano da data-base (sem data-base, o ano atual).',
  },
  { name: 'title', label: 'Título', required: true },
  { name: 'mode', label: 'Tipo de orçamento', type: 'radio', options: modeOptions, required: true, createOnly: true, full: true },
  {
    name: 'reference_dataset_id',
    label: 'Base de referência',
    type: 'select',
    options: 'datasets',
    createOnly: true,
    full: true,
    help: 'UF e regime de encargos vêm da base escolhida (D-P03).',
    visible: (v) => v.mode === 'reference',
  },
  { name: 'project_uuid', label: 'Obra', type: 'select', options: 'projects', help: 'Obra da mesma empresa. O acesso ao orçamento segue o acesso à obra.' },
  { name: 'customer_uuid', label: 'Cliente', type: 'select', options: 'customers' },
  { name: 'reference_date', label: 'Data-base', type: 'date', more: true },
  { name: 'notes', label: 'Observações', type: 'textarea', full: true, more: true },
]

function useLookups() {
  const { can } = useAuth()
  const datasets = useQuery({
    queryKey: ['reference-datasets'],
    queryFn: () => budgetsApi.references.datasets(),
    enabled: can('references.view'),
    staleTime: 300_000,
  })
  const projects = useQuery({
    queryKey: ['projects', 'options'],
    queryFn: () => http<{ data: { uuid: string; code: string; name: string }[] }>('/projects', { query: { per_page: 100 } }),
    enabled: can('projects.view'),
    staleTime: 60_000,
  })
  return {
    companies: useCompanyOptions(),
    customers: useCustomerOptions(),
    datasets: (datasets.data ?? []).map((d) => ({
      value: String(d.id),
      label: `${d.source?.code ?? ''} ${d.state} ${d.competency.slice(5)}/${d.competency.slice(0, 4)} — ${regimeLabel[d.charges_regime]}`,
    })),
    projects: (projects.data?.data ?? []).map((p) => ({ value: p.uuid, label: `${p.code} — ${p.name}` })),
  }
}

/** Orçamentos visíveis (sem obra, ou de obras liberadas — D-P24). Criar abre o orçamento. */
export function BudgetsListPage() {
  const navigate = useNavigate()

  const config: ResourceConfig<Budget> = {
    title: 'Orçamentos',
    description: 'Orçamentos com versões, aprovação e base de referência rastreável.',
    singular: 'orçamento',
    queryKey: 'budgets',
    managePermission: 'budgets.manage',
    fetch: (p) => budgetsApi.list(p),
    rowKey: (r) => r.uuid,
    searchPlaceholder: 'Buscar por código ou título',
    formSize: 'xl',
    useLookups,
    filters: [
      { name: 'status', placeholder: 'Todos os status', options: budgetStatusOptions },
      { name: 'company_uuid', placeholder: 'Todas as empresas', options: 'companies' },
    ],
    columns: [
      { key: 'code', header: 'Código', cell: (r) => <span className="font-medium">{r.code}</span>, sortValue: (r) => r.code },
      { key: 'title', header: 'Título', cell: (r) => r.title, sortValue: (r) => r.title },
      { key: 'project', header: 'Obra', cell: (r) => r.project?.name ?? '—' },
      { key: 'version', header: 'Versão', cell: (r) => (r.current_version ? `v${r.current_version.number}` : '—') },
      { key: 'status', header: 'Status', cell: (r) => <StatusBadge tone={budgetStatusTone[r.status]}>{budgetStatusLabel[r.status]}</StatusBadge> },
      {
        key: 'price',
        header: 'Preço final',
        align: 'right',
        cell: (r) => (r.current_version?.final_price ? formatCurrency(Number(r.current_version.final_price)) : '—'),
        sortValue: (r) => Number(r.current_version?.final_price ?? 0),
      },
    ],
    fields,
    toForm: (r) => ({
      company_uuid: r.company?.uuid ?? '',
      code: r.code,
      title: r.title,
      mode: r.mode,
      reference_dataset_id: r.reference_dataset ? String(r.reference_dataset.id) : '',
      project_uuid: r.project?.uuid ?? '',
      customer_uuid: r.customer?.uuid ?? '',
      reference_date: r.reference_date ?? '',
      notes: r.notes ?? '',
    }),
    create: async (b) => navigate(`/orcamentos/${(await budgetsApi.create(b)).uuid}`),
    update: (r, b) => budgetsApi.update(r.uuid, b),
    remove: (r) => budgetsApi.remove(r.uuid),
    canEditRow: (r) => r.abilities?.edit ?? true,
    onOpen: (r) => navigate(`/orcamentos/${r.uuid}`),
  }

  return <ResourcePage config={config} />
}
