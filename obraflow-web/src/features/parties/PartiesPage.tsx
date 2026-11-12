import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { useToast } from '@/app/providers/ToastProvider'
import { DataTable, Panel, TableToolbar, type Column } from '@/components/data-display/DataTable'
import { DemoBanner, StatusBadge } from '@/components/data-display/indicators'
import { Modal } from '@/components/feedback/Modal'
import { QueryBoundary } from '@/components/feedback/QueryBoundary'
import { FormField, SearchInput, SelectInput, TextInput } from '@/components/forms/fields'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { partiesService } from '@/services'
import type { Party } from '@/types'

const schema = z.object({
  name: z.string().trim().min(3, 'Informe o nome (mín. 3 caracteres)'),
  document: z.string().trim().min(1, 'Informe o documento'),
  email: z.string().trim().email('E-mail inválido'),
  phone: z.string().trim().min(1, 'Informe o telefone'),
  city: z.string().trim().min(1, 'Informe a cidade'),
})
type FormValues = z.infer<typeof schema>

const columns: Column<Party>[] = [
  { key: 'name', header: 'Nome', cell: (p) => <span className="font-medium">{p.name}</span>, sortValue: (p) => p.name },
  { key: 'document', header: 'Documento', cell: (p) => p.document },
  { key: 'email', header: 'E-mail', cell: (p) => p.email },
  { key: 'phone', header: 'Telefone', cell: (p) => p.phone },
  { key: 'city', header: 'Cidade', cell: (p) => p.city },
  { key: 'status', header: 'Situação', cell: (p) => <StatusBadge tone={p.status === 'active' ? 'success' : 'neutral'}>{p.status === 'active' ? 'Ativo' : 'Inativo'}</StatusBadge> },
]

/** Tela compartilhada por Clientes e Fornecedores (mesma estrutura, serviço diferente). */
export function PartiesPage({ kind }: { kind: 'customers' | 'suppliers' }) {
  const isCustomer = kind === 'customers'
  const noun = isCustomer ? 'cliente' : 'fornecedor'
  const query = useQuery({ queryKey: [kind], queryFn: isCustomer ? partiesService.customers : partiesService.suppliers })
  const { notify } = useToast()
  const [open, setOpen] = useState(false)
  const [extra, setExtra] = useState<Party[]>([])
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')

  const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { name: '', document: '', email: '', phone: '', city: '' } })
  const onSubmit = form.handleSubmit((v) => {
    setExtra((e) => [{ id: `new-${e.length + 1}`, status: 'active', ...v }, ...e])
    notify('success', `${isCustomer ? 'Cliente' : 'Fornecedor'} adicionado (demonstração)`, 'Registro existe apenas nesta sessão.')
    form.reset()
    setOpen(false)
  })

  return (
    <>
      <PageHeader
        title={isCustomer ? 'Clientes' : 'Fornecedores'}
        description={isCustomer ? 'Cadastro de clientes e contratantes.' : 'Cadastro de fornecedores de materiais, serviços e locações.'}
        actions={<Button onClick={() => setOpen(true)}><Plus className="size-4" /> Novo {noun}</Button>}
      />
      <DemoBanner />
      <QueryBoundary query={query}>
        {(data) => {
          const term = search.trim().toLowerCase()
          const rows = [...extra, ...data].filter(
            (p) => (!term || p.name.toLowerCase().includes(term) || p.document.includes(term)) && (!status || p.status === status),
          )
          return (
            <Panel>
              <TableToolbar>
                <SearchInput className="w-72" placeholder={`Pesquisar ${noun}…`} aria-label={`Pesquisar ${noun}`} value={search} onChange={(e) => setSearch(e.target.value)} />
                <FormField label="Situação" className="w-40">
                  <SelectInput value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">Todas</option>
                    <option value="active">Ativos</option>
                    <option value="inactive">Inativos</option>
                  </SelectInput>
                </FormField>
              </TableToolbar>
              <DataTable caption={`Lista de ${noun}s`} columns={columns} rows={rows} rowKey={(p) => p.id} />
            </Panel>
          )
        }}
      </QueryBoundary>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={`Cadastrar ${noun}`}
        description="Formulário demonstrativo — nada é enviado ao servidor."
        footer={<><Button variant="outline" onClick={() => setOpen(false)}>Cancelar</Button><Button onClick={onSubmit}>Salvar</Button></>}
      >
        <form onSubmit={onSubmit} noValidate className="grid gap-4 sm:grid-cols-2">
          <FormField label="Nome / razão social" required error={form.formState.errors.name?.message} className="sm:col-span-2"><TextInput {...form.register('name')} /></FormField>
          <FormField label="CPF / CNPJ" required error={form.formState.errors.document?.message}><TextInput {...form.register('document')} /></FormField>
          <FormField label="Telefone" required error={form.formState.errors.phone?.message}><TextInput {...form.register('phone')} /></FormField>
          <FormField label="E-mail" required error={form.formState.errors.email?.message}><TextInput type="email" {...form.register('email')} /></FormField>
          <FormField label="Cidade" required error={form.formState.errors.city?.message}><TextInput {...form.register('city')} /></FormField>
        </form>
      </Modal>
    </>
  )
}
