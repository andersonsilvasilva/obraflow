import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { useToast } from '@/app/providers/ToastProvider'
import { DataTable, Panel, TableToolbar, type Column } from '@/components/data-display/DataTable'
import { DemoBanner, ProgressIndicator, StatusBadge } from '@/components/data-display/indicators'
import { Modal } from '@/components/feedback/Modal'
import { QueryBoundary } from '@/components/feedback/QueryBoundary'
import { CurrencyInput, FormField, SelectInput, TextInput } from '@/components/forms/fields'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { projectStatusMeta } from '@/features/shared'
import { projectsService } from '@/services'
import type { Project, ProjectStatus } from '@/types'
import { formatCurrency, formatDate } from '@/utils/format'

const schema = z
  .object({
    code: z.string().trim().min(1, 'Informe o código'),
    name: z.string().trim().min(3, 'Informe o nome da obra (mín. 3 caracteres)'),
    customer: z.string().min(1, 'Selecione o cliente'),
    manager: z.string().trim().min(1, 'Informe o responsável'),
    startDate: z.string().min(1, 'Informe a data de início'),
    expectedEndDate: z.string().min(1, 'Informe a previsão de término'),
    budget: z.number().min(0),
  })
  .refine((v) => !v.startDate || !v.expectedEndDate || v.expectedEndDate >= v.startDate, {
    path: ['expectedEndDate'],
    message: 'O término não pode ser anterior ao início',
  })
type FormValues = z.infer<typeof schema>

const columns: Column<Project>[] = [
  { key: 'code', header: 'Código', cell: (p) => <span className="font-medium">{p.code}</span>, sortValue: (p) => p.code },
  { key: 'name', header: 'Obra', cell: (p) => p.name, sortValue: (p) => p.name },
  { key: 'customer', header: 'Cliente', cell: (p) => p.customer },
  { key: 'manager', header: 'Responsável', cell: (p) => p.manager },
  { key: 'start', header: 'Início', cell: (p) => formatDate(p.startDate), sortValue: (p) => p.startDate },
  { key: 'end', header: 'Previsão', cell: (p) => formatDate(p.expectedEndDate), sortValue: (p) => p.expectedEndDate },
  { key: 'budget', header: 'Orçamento', align: 'right', cell: (p) => formatCurrency(p.budget), sortValue: (p) => p.budget },
  { key: 'progress', header: 'Progresso', cell: (p) => <ProgressIndicator value={p.progress} label={`Progresso de ${p.name}`} /> },
  { key: 'status', header: 'Situação', cell: (p) => <StatusBadge tone={projectStatusMeta[p.status].tone}>{projectStatusMeta[p.status].label}</StatusBadge> },
  { key: 'actions', header: 'Ações', cell: (p) => <Button variant="ghost" size="sm" aria-label={`Abrir ${p.name}`}>Abrir</Button> },
]

export function ProjectsPage() {
  const query = useQuery({ queryKey: ['projects'], queryFn: projectsService.list })
  const { notify } = useToast()
  const [extra, setExtra] = useState<Project[]>([])
  const [open, setOpen] = useState(false)
  const [filters, setFilters] = useState({ customer: '', manager: '', status: '', from: '', to: '' })

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { code: '', name: '', customer: '', manager: '', startDate: '', expectedEndDate: '', budget: 0 },
  })

  const onSubmit = form.handleSubmit((v) => {
    setExtra((e) => [
      { id: `new-${e.length + 1}`, progress: 0, status: 'planning' as ProjectStatus, ...v },
      ...e,
    ])
    notify('success', 'Obra adicionada (demonstração)', 'O registro existe apenas nesta sessão e não foi salvo no servidor.')
    form.reset()
    setOpen(false)
  })

  return (
    <>
      <PageHeader
        title="Obras"
        description="Acompanhe o portfólio de obras, prazos, orçamentos e progresso."
        actions={<Button onClick={() => setOpen(true)}><Plus className="size-4" /> Nova obra</Button>}
      />
      <DemoBanner />

      <QueryBoundary query={query}>
        {(data) => <ProjectsTable all={[...extra, ...data]} filters={filters} setFilters={setFilters} />}
      </QueryBoundary>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Cadastrar obra"
        description="Formulário demonstrativo — nada é enviado ao servidor."
        footer={
          <>
            <Button variant="outline" onClick={() => setOpen(false)}>Cancelar</Button>
            <Button onClick={onSubmit}>Salvar obra</Button>
          </>
        }
      >
        <form onSubmit={onSubmit} noValidate className="grid gap-4 sm:grid-cols-2">
          <FormField label="Código" required error={form.formState.errors.code?.message}>
            <TextInput {...form.register('code')} />
          </FormField>
          <FormField label="Nome da obra" required error={form.formState.errors.name?.message}>
            <TextInput {...form.register('name')} />
          </FormField>
          <FormField label="Cliente" required error={form.formState.errors.customer?.message}>
            <SelectInput {...form.register('customer')}>
              <option value="">Selecione…</option>
              <option>Cliente Demo 01</option>
              <option>Cliente Demo 02</option>
              <option>Cliente Demo 03</option>
            </SelectInput>
          </FormField>
          <FormField label="Responsável" required error={form.formState.errors.manager?.message}>
            <TextInput {...form.register('manager')} />
          </FormField>
          <FormField label="Data de início" required error={form.formState.errors.startDate?.message}>
            <TextInput type="date" {...form.register('startDate')} />
          </FormField>
          <FormField label="Previsão de término" required error={form.formState.errors.expectedEndDate?.message}>
            <TextInput type="date" {...form.register('expectedEndDate')} />
          </FormField>
          <FormField label="Orçamento previsto" className="sm:col-span-2">
            <CurrencyInput value={form.watch('budget')} onValueChange={(n) => form.setValue('budget', n)} />
          </FormField>
        </form>
      </Modal>
    </>
  )
}

interface Filters { customer: string; manager: string; status: string; from: string; to: string }

function ProjectsTable({ all, filters, setFilters }: { all: Project[]; filters: Filters; setFilters: (f: Filters) => void }) {
  const customers = useMemo(() => [...new Set(all.map((p) => p.customer))], [all])
  const managers = useMemo(() => [...new Set(all.map((p) => p.manager))], [all])

  const rows = all.filter(
    (p) =>
      (!filters.customer || p.customer === filters.customer) &&
      (!filters.manager || p.manager === filters.manager) &&
      (!filters.status || p.status === filters.status) &&
      (!filters.from || p.startDate >= filters.from) &&
      (!filters.to || p.startDate <= filters.to),
  )
  const set = (patch: Partial<Filters>) => setFilters({ ...filters, ...patch })

  return (
    <Panel>
      <TableToolbar>
        <FormField label="Cliente" className="w-44">
          <SelectInput value={filters.customer} onChange={(e) => set({ customer: e.target.value })}>
            <option value="">Todos</option>
            {customers.map((c) => <option key={c}>{c}</option>)}
          </SelectInput>
        </FormField>
        <FormField label="Responsável" className="w-44">
          <SelectInput value={filters.manager} onChange={(e) => set({ manager: e.target.value })}>
            <option value="">Todos</option>
            {managers.map((c) => <option key={c}>{c}</option>)}
          </SelectInput>
        </FormField>
        <FormField label="Situação" className="w-44">
          <SelectInput value={filters.status} onChange={(e) => set({ status: e.target.value })}>
            <option value="">Todas</option>
            {Object.entries(projectStatusMeta).map(([k, m]) => <option key={k} value={k}>{m.label}</option>)}
          </SelectInput>
        </FormField>
        <FormField label="Início de">
          <TextInput type="date" value={filters.from} onChange={(e) => set({ from: e.target.value })} />
        </FormField>
        <FormField label="Início até">
          <TextInput type="date" value={filters.to} onChange={(e) => set({ to: e.target.value })} />
        </FormField>
        <Button variant="ghost" onClick={() => setFilters({ customer: '', manager: '', status: '', from: '', to: '' })}>Limpar</Button>
      </TableToolbar>
      <DataTable caption="Lista de obras" columns={columns} rows={rows} rowKey={(p) => p.id} pageSize={8} />
    </Panel>
  )
}
