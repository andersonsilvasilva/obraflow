import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Calculator, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { StatusBadge } from '@/components/data-display/indicators'
import { Alert } from '@/components/feedback/Alert'
import { Modal } from '@/components/feedback/Modal'
import { LoadingState } from '@/components/feedback/states'
import { FormField, SelectInput, TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-context'
import { ApiError, getErrorMessage } from '@/lib/api-error'
import { formatCurrency, formatDateOrDash } from '@/utils/format'
import { catalogApi, laborPreview, type CatalogResource } from './api'

const contractLabel: Record<string, string> = { monthly: 'Mensalista', hourly: 'Horista', piecework: 'Empreitada', daily: 'Diarista' }

/** Ícone na linha da mão de obra: abre o balanço (D-P39). */
export function LaborCostButton({ resource }: { resource: CatalogResource }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <Button variant="ghost" size="icon" aria-label={`Balanço de ${resource.name}`} title="Balanço (custo da mão de obra)" onClick={() => setOpen(true)}>
        <Calculator className="size-4" />
      </Button>
      {open && <LaborCostModal resource={resource} onClose={() => setOpen(false)} />}
    </>
  )
}

const empty = { effective_date: new Date().toISOString().slice(0, 10), base_salary: '', extra_hour: '', charges_percent: '', admin_percent: '', monthly_hours: '220', labor_type: 'direct', contract_type: 'monthly' }

/**
 * Balanço da mão de obra: histórico por vigência e novo balanço. Preço/hora = salário × (1 + encargos) × (1 + administração)
 * ÷ carga horária mensal. A prévia é só exibição; o valor oficial é o calculado e gravado pela API.
 */
function LaborCostModal({ resource, onClose }: { resource: CatalogResource; onClose: () => void }) {
  const { can } = useAuth()
  const canManage = can('resources.manage')
  const client = useQueryClient()
  const [form, setForm] = useState(empty)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const key = ['labor-costs', resource.uuid]
  const list = useQuery({ queryKey: key, queryFn: () => catalogApi.laborCosts(resource.uuid) })
  const refresh = () => {
    client.invalidateQueries({ queryKey: key })
    client.invalidateQueries({ queryKey: ['catalog-labor'] })
  }

  const add = useMutation({
    mutationFn: () =>
      catalogApi.addLaborCost(resource.uuid, Object.fromEntries(Object.entries(form).map(([k, v]) => [k, typeof v === 'string' ? v.replace(',', '.') || null : v]))),
    onSuccess: () => {
      setForm(empty)
      setErrors({})
      setError(null)
      refresh()
    },
    onError: (e) => (e instanceof ApiError && e.details ? setErrors(Object.fromEntries(Object.entries(e.details).map(([k, v]) => [k, v[0]]))) : setError(getErrorMessage(e))),
  })
  const remove = useMutation({ mutationFn: (id: number) => catalogApi.removeLaborCost(resource.uuid, id), onSuccess: refresh, onError: (e) => setError(getErrorMessage(e)) })

  const preview = laborPreview(form.base_salary, form.charges_percent, form.admin_percent, form.monthly_hours)
  const set = (k: keyof typeof empty) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value })

  return (
    <Modal open onClose={onClose} title="Balanço da mão de obra" description={resource.name} size="xl">
      {error && (
        <Alert tone="danger" className="mb-3">
          {error}
        </Alert>
      )}
      {canManage && (
        <form
          className="mb-5 rounded-lg border p-4"
          onSubmit={(e) => {
            e.preventDefault()
            add.mutate()
          }}
        >
          <div className="grid gap-3 sm:grid-cols-3">
            <FormField label="Salário base (R$)" required error={errors.base_salary}>
              <TextInput inputMode="decimal" value={form.base_salary} onChange={set('base_salary')} />
            </FormField>
            <FormField label="Hora adicional (R$)" error={errors.extra_hour} hint="Informativo">
              <TextInput inputMode="decimal" value={form.extra_hour} onChange={set('extra_hour')} />
            </FormField>
            <FormField label="Carga horária mensal (h)" required error={errors.monthly_hours}>
              <TextInput inputMode="decimal" value={form.monthly_hours} onChange={set('monthly_hours')} />
            </FormField>
            <FormField label="Encargos (%)" required error={errors.charges_percent}>
              <TextInput inputMode="decimal" value={form.charges_percent} onChange={set('charges_percent')} />
            </FormField>
            <FormField label="Administração (%)" required error={errors.admin_percent}>
              <TextInput inputMode="decimal" value={form.admin_percent} onChange={set('admin_percent')} />
            </FormField>
            <FormField label="Vigência a partir de" required error={errors.effective_date}>
              <TextInput type="date" value={form.effective_date} onChange={set('effective_date')} />
            </FormField>
            <fieldset className="flex flex-col gap-1.5">
              <legend className="mb-1.5 text-sm font-medium">Mão de obra</legend>
              <div className="flex gap-4">
                {[
                  ['indirect', 'Indireta'],
                  ['direct', 'Direta'],
                ].map(([v, l]) => (
                  <label key={v} className="flex items-center gap-2 text-sm">
                    <input type="radio" className="accent-(--primary) size-4" checked={form.labor_type === v} onChange={() => setForm({ ...form, labor_type: v })} /> {l}
                  </label>
                ))}
              </div>
            </fieldset>
            <FormField label="Tipo de contrato">
              <SelectInput value={form.contract_type} onChange={set('contract_type')}>
                {Object.entries(contractLabel).map(([v, l]) => (
                  <option key={v} value={v}>
                    {l}
                  </option>
                ))}
              </SelectInput>
            </FormField>
            <div className="flex flex-col justify-end">
              <p className="text-muted-foreground text-xs">Prévia do custo/hora</p>
              <p className="text-xl font-semibold tabular-nums">{preview === null ? '—' : formatCurrency(Math.round(preview * 100) / 100)}</p>
            </div>
          </div>
          <p className="text-muted-foreground mt-2 text-xs">Custo/hora = salário × (1 + encargos) × (1 + administração) ÷ carga horária. O balanço vigente (data até hoje) define o preço do insumo.</p>
          <div className="mt-3 flex justify-end">
            <Button type="submit" size="lg" disabled={add.isPending || !form.base_salary}>
              Salvar balanço
            </Button>
          </div>
        </form>
      )}

      <h3 className="mb-2 text-sm font-semibold">Histórico</h3>
      {list.isPending ? (
        <LoadingState />
      ) : (list.data ?? []).length === 0 ? (
        <p className="text-muted-foreground text-sm">Nenhum balanço. O preço do insumo é o informado no cadastro.</p>
      ) : (
        <table className="w-full text-sm">
          <caption className="sr-only">Histórico de balanços</caption>
          <thead className="bg-muted">
            <tr className="text-left">
              {['Vigência', 'Salário', 'Encargos', 'Adm.', 'Horas', 'Contrato', 'Custo/hora', ''].map((h, i) => (
                <th key={i} scope="col" className="px-2 py-1.5 font-semibold">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {list.data!.map((c, i) => (
              <tr key={c.id} className="border-b">
                <td className="px-2 py-1.5">
                  {formatDateOrDash(c.effective_date)} {c.current && i === list.data!.findIndex((x) => x.current) && <StatusBadge tone="success">Vigente</StatusBadge>}
                  {!c.current && <StatusBadge tone="neutral">Futuro</StatusBadge>}
                </td>
                <td className="px-2 py-1.5 tabular-nums">{formatCurrency(Number(c.base_salary))}</td>
                <td className="px-2 py-1.5">{Number(c.charges_percent).toLocaleString('pt-BR')}%</td>
                <td className="px-2 py-1.5">{Number(c.admin_percent).toLocaleString('pt-BR')}%</td>
                <td className="px-2 py-1.5">{Number(c.monthly_hours).toLocaleString('pt-BR')}</td>
                <td className="px-2 py-1.5">{c.contract_type ? contractLabel[c.contract_type] : '—'}</td>
                <td className="px-2 py-1.5 font-medium tabular-nums">{formatCurrency(Number(c.hourly_cost))}</td>
                <td className="px-2 py-1 text-right">
                  <Button variant="ghost" size="icon" aria-label="Excluir balanço" disabled={!canManage} onClick={() => remove.mutate(c.id)}>
                    <Trash2 className="size-4" />
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Modal>
  )
}
