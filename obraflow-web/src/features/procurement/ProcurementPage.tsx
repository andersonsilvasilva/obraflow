import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useFieldArray, useForm } from 'react-hook-form'
import { z } from 'zod'
import { useToast } from '@/app/providers/ToastProvider'
import { DataTable, Panel, TableToolbar, type Column } from '@/components/data-display/DataTable'
import { DemoBanner, StatusBadge } from '@/components/data-display/indicators'
import { Modal } from '@/components/feedback/Modal'
import { QueryBoundary } from '@/components/feedback/QueryBoundary'
import { FormField, SearchInput, SelectInput, TextArea, TextInput } from '@/components/forms/fields'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { priorityMeta, requestStatusMeta } from '@/features/shared'
import { procurementService } from '@/services'
import type { PurchaseRequest, RequestPriority } from '@/types'
import { formatDate } from '@/utils/format'

const schema = z.object({
  project: z.string().min(1, 'Selecione a obra'),
  priority: z.enum(['low', 'medium', 'high', 'urgent']),
  notes: z.string().optional(),
  items: z
    .array(
      z.object({
        description: z.string().trim().min(1, 'Descreva o item'),
        unit: z.string().trim().min(1, 'Unidade'),
        quantity: z.number({ error: 'Informe a quantidade' }).positive('Deve ser maior que zero'),
      }),
    )
    .min(1, 'Inclua ao menos um item'),
})
type FormValues = z.infer<typeof schema>

const columns: Column<PurchaseRequest>[] = [
  { key: 'number', header: 'Número', cell: (r) => <span className="font-medium">{r.number}</span>, sortValue: (r) => r.number },
  { key: 'project', header: 'Obra', cell: (r) => r.project },
  { key: 'requester', header: 'Solicitante', cell: (r) => r.requester },
  { key: 'date', header: 'Data', cell: (r) => formatDate(r.date), sortValue: (r) => r.date },
  { key: 'items', header: 'Itens', align: 'right', cell: (r) => r.itemsCount },
  { key: 'priority', header: 'Prioridade', cell: (r) => <StatusBadge tone={priorityMeta[r.priority].tone}>{priorityMeta[r.priority].label}</StatusBadge> },
  { key: 'status', header: 'Situação', cell: (r) => <StatusBadge tone={requestStatusMeta[r.status].tone}>{requestStatusMeta[r.status].label}</StatusBadge> },
]

export function ProcurementPage() {
  const query = useQuery({ queryKey: ['purchase-requests'], queryFn: procurementService.list })
  const { notify } = useToast()
  const [open, setOpen] = useState(false)
  const [extra, setExtra] = useState<PurchaseRequest[]>([])
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { project: '', priority: 'medium', notes: '', items: [{ description: '', unit: 'un', quantity: 1 }] },
  })
  const items = useFieldArray({ control: form.control, name: 'items' })

  const onSubmit = form.handleSubmit((v) => {
    setExtra((e) => [
      {
        id: `new-${e.length + 1}`,
        number: `SOL-${String(200 + e.length + 1).padStart(4, '0')}`,
        project: v.project,
        requester: 'Usuário Demo',
        date: new Date().toISOString().slice(0, 10),
        itemsCount: v.items.length,
        priority: v.priority as RequestPriority,
        status: 'pending',
      },
      ...e,
    ])
    notify('success', 'Solicitação criada (demonstração)', 'Registro existe apenas nesta sessão.')
    form.reset()
    setOpen(false)
  })

  return (
    <>
      <PageHeader
        title="Suprimentos"
        description="Solicitações de materiais por obra, com acompanhamento e aprovação."
        actions={<Button onClick={() => setOpen(true)}><Plus className="size-4" /> Nova solicitação</Button>}
      />
      <DemoBanner />
      <QueryBoundary query={query}>
        {(data) => {
          const term = search.trim().toLowerCase()
          const rows = [...extra, ...data].filter(
            (r) => (!term || r.number.toLowerCase().includes(term) || r.project.toLowerCase().includes(term)) && (!status || r.status === status),
          )
          return (
            <Panel>
              <TableToolbar>
                <SearchInput className="w-72" placeholder="Pesquisar por número ou obra…" aria-label="Pesquisar solicitações" value={search} onChange={(e) => setSearch(e.target.value)} />
                <FormField label="Situação" className="w-48">
                  <SelectInput value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">Todas</option>
                    {Object.entries(requestStatusMeta).map(([k, m]) => <option key={k} value={k}>{m.label}</option>)}
                  </SelectInput>
                </FormField>
              </TableToolbar>
              <DataTable caption="Solicitações de materiais" columns={columns} rows={rows} rowKey={(r) => r.id} />
            </Panel>
          )
        }}
      </QueryBoundary>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Solicitação de materiais"
        description="Formulário demonstrativo — nada é enviado ao servidor."
        size="xl"
        footer={<><Button variant="outline" onClick={() => setOpen(false)}>Cancelar</Button><Button onClick={onSubmit}>Enviar solicitação</Button></>}
      >
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Obra" required error={form.formState.errors.project?.message}>
              <SelectInput {...form.register('project')}>
                <option value="">Selecione…</option>
                <option>Residencial Modelo A</option>
                <option>Galpão Logístico Demo</option>
                <option>Edifício Comercial Demo</option>
              </SelectInput>
            </FormField>
            <FormField label="Prioridade">
              <SelectInput {...form.register('priority')}>
                {Object.entries(priorityMeta).map(([k, m]) => <option key={k} value={k}>{m.label}</option>)}
              </SelectInput>
            </FormField>
          </div>

          <fieldset className="flex flex-col gap-2">
            <legend className="mb-1 text-sm font-medium">Itens solicitados</legend>
            {items.fields.map((f, i) => (
              <div key={f.id} className="grid grid-cols-[1fr_5rem_6rem_auto] items-start gap-2">
                <FormField label={i === 0 ? 'Descrição' : 'Descrição do item'} error={form.formState.errors.items?.[i]?.description?.message}>
                  <TextInput {...form.register(`items.${i}.description`)} />
                </FormField>
                <FormField label="Un." error={form.formState.errors.items?.[i]?.unit?.message}>
                  <TextInput {...form.register(`items.${i}.unit`)} />
                </FormField>
                <FormField label="Qtd." error={form.formState.errors.items?.[i]?.quantity?.message}>
                  <TextInput type="number" step="any" {...form.register(`items.${i}.quantity`, { valueAsNumber: true })} />
                </FormField>
                <Button type="button" variant="ghost" size="icon" className="mt-6" onClick={() => items.remove(i)} disabled={items.fields.length === 1} aria-label={`Remover item ${i + 1}`}>
                  <Trash2 className="size-4" />
                </Button>
              </div>
            ))}
            {form.formState.errors.items?.message && <p role="alert" className="text-destructive text-xs">{form.formState.errors.items.message}</p>}
            <Button type="button" variant="outline" size="sm" className="self-start" onClick={() => items.append({ description: '', unit: 'un', quantity: 1 })}>
              <Plus className="size-4" /> Adicionar item
            </Button>
          </fieldset>

          <FormField label="Observações"><TextArea {...form.register('notes')} /></FormField>
        </form>
      </Modal>
    </>
  )
}
