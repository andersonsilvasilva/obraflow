import { zodResolver } from '@hookform/resolvers/zod'
import { Database, PenLine, UserRound } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { useToast } from '@/app/providers/ToastProvider'
import { DemoBanner } from '@/components/data-display/indicators'
import { Panel } from '@/components/data-display/DataTable'
import { FormField, SelectInput, TextArea, TextInput } from '@/components/forms/fields'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

const schema = z.object({
  mode: z.enum(['customer', 'manual', 'reference']),
  customer: z.string().min(1, 'Selecione o cliente'),
  project: z.string().min(1, 'Selecione a obra'),
  name: z.string().trim().min(3, 'Informe o nome do orçamento (mín. 3 caracteres)'),
  description: z.string().optional(),
  owner: z.string().trim().min(1, 'Informe o responsável'),
  referenceDate: z.string().min(1, 'Informe a data de referência'),
  location: z.string().trim().min(1, 'Informe a localidade'),
  state: z.string().min(1, 'Selecione o estado'),
  socialCharges: z.string().min(1, 'Selecione o regime de encargos'),
  notes: z.string().optional(),
})
type FormValues = z.infer<typeof schema>

const modes = [
  { value: 'customer', label: 'Orçamento por cliente', desc: 'Parte de uma solicitação ou proposta de um cliente.', icon: UserRound },
  { value: 'manual', label: 'Orçamento manual', desc: 'Itens e preços informados diretamente pelo orçamentista.', icon: PenLine },
  { value: 'reference', label: 'Referência técnica', desc: 'Usa tabelas técnicas importadas (ex.: SINAPI, SUDECAP) quando disponíveis.', icon: Database },
] as const

export function BudgetCreatePage() {
  const navigate = useNavigate()
  const { notify } = useToast()
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { mode: 'customer', customer: '', project: '', name: '', description: '', owner: '', referenceDate: '', location: '', state: '', socialCharges: '', notes: '' },
  })
  const { errors } = form.formState
  const mode = form.watch('mode')

  const onSubmit = form.handleSubmit(() => {
    notify('success', 'Orçamento criado (demonstração)', 'Abrindo o preenchimento com itens fictícios.')
    navigate('/orcamentos/b1/preencher')
  })

  return (
    <>
      <PageHeader title="Novo orçamento" description="Defina a origem, a obra e os dados gerais antes de preencher os itens." />
      <DemoBanner />
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
        <Panel title="Tipo de orçamento">
          <fieldset className="grid gap-3 p-4 md:grid-cols-3">
            <legend className="sr-only">Tipo de orçamento</legend>
            {modes.map((m) => (
              <label
                key={m.value}
                className={cn(
                  'flex cursor-pointer gap-3 rounded-lg border p-3 has-focus-visible:ring-3 has-focus-visible:ring-ring/40',
                  mode === m.value ? 'border-primary bg-primary-soft' : 'hover:bg-muted',
                )}
              >
                <input type="radio" value={m.value} className="sr-only" {...form.register('mode')} />
                <m.icon className="text-primary mt-0.5 size-5 shrink-0" aria-hidden />
                <span>
                  <span className="block text-sm font-medium">{m.label}</span>
                  <span className="text-muted-foreground block text-xs">{m.desc}</span>
                </span>
              </label>
            ))}
          </fieldset>
          {mode === 'reference' && (
            <p className="text-muted-foreground border-t px-4 py-3 text-xs">
              As bases de referência (SINAPI, SUDECAP) ainda não estão integradas. Quando estiverem, cada preço terá origem, competência, localidade e versão identificáveis.
            </p>
          )}
        </Panel>

        <Panel title="Dados gerais">
          <div className="grid gap-4 p-4 md:grid-cols-2">
            <FormField label="Cliente" required error={errors.customer?.message}>
              <SelectInput {...form.register('customer')}>
                <option value="">Selecione…</option>
                <option>Cliente Demo 01</option><option>Cliente Demo 02</option><option>Cliente Demo 03</option>
              </SelectInput>
            </FormField>
            <FormField label="Obra" required error={errors.project?.message}>
              <SelectInput {...form.register('project')}>
                <option value="">Selecione…</option>
                <option>Residencial Modelo A</option><option>Galpão Logístico Demo</option><option>Edifício Comercial Demo</option>
              </SelectInput>
            </FormField>
            <FormField label="Nome do orçamento" required error={errors.name?.message} className="md:col-span-2"><TextInput {...form.register('name')} /></FormField>
            <FormField label="Descrição" className="md:col-span-2"><TextArea {...form.register('description')} /></FormField>
            <FormField label="Responsável" required error={errors.owner?.message}><TextInput {...form.register('owner')} /></FormField>
            <FormField label="Data de referência" required error={errors.referenceDate?.message}><TextInput type="date" {...form.register('referenceDate')} /></FormField>
            <FormField label="Localidade" required error={errors.location?.message}><TextInput placeholder="Cidade" {...form.register('location')} /></FormField>
            <FormField label="Estado (UF)" required error={errors.state?.message} hint="Define a localidade das referências de preço.">
              <SelectInput {...form.register('state')}>
                <option value="">Selecione…</option>
                {['AL', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PR', 'RJ', 'RN', 'RS', 'SC', 'SE', 'SP'].map((uf) => <option key={uf}>{uf}</option>)}
              </SelectInput>
            </FormField>
            <FormField label="Encargos sociais" required error={errors.socialCharges?.message} hint="Regime de encargos aplicável à mão de obra.">
              <SelectInput {...form.register('socialCharges')}>
                <option value="">Selecione…</option>
                <option value="with">Com desoneração</option>
                <option value="without">Sem desoneração</option>
                <option value="n/a">Não se aplica</option>
              </SelectInput>
            </FormField>
            <FormField label="Observações" className="md:col-span-2"><TextArea {...form.register('notes')} /></FormField>
          </div>
        </Panel>

        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => navigate('/orcamentos')}>Cancelar</Button>
          <Button type="submit">Criar e preencher</Button>
        </div>
      </form>
    </>
  )
}

