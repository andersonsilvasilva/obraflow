import { zodResolver } from '@hookform/resolvers/zod'
import { FolderPlus, ListChecks, Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import { z } from 'zod'
import { useToast } from '@/app/providers/ToastProvider'
import { Panel } from '@/components/data-display/DataTable'
import { DemoBanner, MetricCard, StatusBadge } from '@/components/data-display/indicators'
import { ConfirmDialog, Modal } from '@/components/feedback/Modal'
import { EmptyState } from '@/components/feedback/states'
import { FormField, SelectInput, TextInput } from '@/components/forms/fields'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button, buttonVariants } from '@/components/ui/button'
import type { BudgetGroup, BudgetItem, ResourceType } from '@/types'
import { formatCurrency, formatDecimal } from '@/utils/format'
import { useBudgetWorkspace } from './BudgetWorkspace'
import { budgetTotals, effectiveUnitPrice, groupTotal, itemTotal, resourceTypeLabel } from './calc'
import { CostComposition } from './CompositionPanel'

const itemSchema = z.object({
  code: z.string().trim().min(1, 'Informe o código'),
  description: z.string().trim().min(3, 'Descreva o item'),
  type: z.enum(['service', 'material', 'labor', 'equipment']),
  unit: z.string().trim().min(1, 'Informe a unidade'),
  quantity: z.number({ error: 'Informe a quantidade' }).positive('Deve ser maior que zero'),
  unitPrice: z.number({ error: 'Informe o preço' }).min(0, 'Não pode ser negativo'),
  compositionId: z.string().optional(),
})
type ItemForm = z.infer<typeof itemSchema>

const emptyItem: ItemForm = { code: '', description: '', type: 'material', unit: 'un', quantity: 1, unitPrice: 0, compositionId: '' }

type Editing = { groupId: string; item: BudgetItem | null } | null

export function BudgetEditorPage() {
  const { groups, setGroups, bdi, setBdi, discount, setDiscount, compositions } = useBudgetWorkspace()
  const { notify } = useToast()
  const [editing, setEditing] = useState<Editing>(null)
  const [viewCompId, setViewCompId] = useState<string | null>(null)
  const [removing, setRemoving] = useState<{ groupId: string; itemId: string } | null>(null)
  const [newGroup, setNewGroup] = useState<string | null>(null)

  const totals = budgetTotals(groups, compositions, bdi, discount)
  const form = useForm<ItemForm>({ resolver: zodResolver(itemSchema), defaultValues: emptyItem })
  const compositionId = form.watch('compositionId')

  const openItem = (groupId: string, item: BudgetItem | null) => {
    form.reset(item ? { ...item, compositionId: item.compositionId ?? '' } : emptyItem)
    setEditing({ groupId, item })
  }

  const saveItem = form.handleSubmit((v) => {
    if (!editing) return
    const composition = v.compositionId ? compositions.find((c) => c.id === v.compositionId) : undefined
    const base = { ...v, compositionId: composition?.id, unitPrice: composition ? 0 : v.unitPrice }
    setGroups((gs) =>
      gs.map((g) => {
        if (g.id !== editing.groupId) return g
        const items = editing.item
          ? g.items.map((i) => (i.id === editing.item!.id ? { ...i, ...base } : i))
          : [...g.items, { id: `it-${Date.now()}`, ...base }]
        return { ...g, items }
      }),
    )
    notify('success', editing.item ? 'Item atualizado' : 'Item adicionado')
    setEditing(null)
  })

  const addGroup = () => {
    const name = newGroup?.trim()
    if (!name) return
    setGroups((gs) => [...gs, { id: `g-${Date.now()}`, name, items: [] } satisfies BudgetGroup])
    setNewGroup(null)
  }

  const confirmRemove = () => {
    if (!removing) return
    setGroups((gs) => gs.map((g) => (g.id === removing.groupId ? { ...g, items: g.items.filter((i) => i.id !== removing.itemId) } : g)))
    notify('info', 'Item removido')
    setRemoving(null)
  }

  const viewing = compositions.find((c) => c.id === viewCompId) ?? null
  const errors = form.formState.errors

  return (
    <>
      <PageHeader
        title="Preenchimento do orçamento"
        description="Organize etapas, serviços, composições e insumos. Os valores são calculados localmente."
        actions={
          <>
            <Button variant="ghost" onClick={() => notify('info', 'Orçamento reprovado (demonstração)', 'Nenhuma alteração foi enviada ao servidor.')}>Reprovar</Button>
            <Button variant="outline" onClick={() => notify('success', 'Orçamento aprovado (demonstração)', 'Em produção a aprovação gera versão imutável e trilha de auditoria.')}>Aprovar</Button>
            <Button variant="outline" onClick={() => setNewGroup('')}><FolderPlus className="size-4" /> Nova etapa</Button>
            <Link to="../finalizacao" className={buttonVariants()}><ListChecks className="size-4" /> Resumo e finalização</Link>
          </>
        }
      />
      <DemoBanner>Cálculos demonstrativos — não seguem metodologia oficial de orçamento.</DemoBanner>

      <div className="grid gap-4 xl:grid-cols-[1fr_18rem]">
        <div className="flex flex-col gap-4">
          {groups.length === 0 && <Panel><EmptyState title="Nenhuma etapa" description="Crie uma etapa para começar a incluir serviços." /></Panel>}
          {groups.map((g) => (
            <Panel
              key={g.id}
              title={g.name}
              actions={
                <div className="flex items-center gap-3">
                  <span className="text-sm font-semibold tabular-nums">{formatCurrency(groupTotal(g, compositions))}</span>
                  <Button size="sm" variant="outline" onClick={() => openItem(g.id, null)}><Plus className="size-4" /> Item</Button>
                </div>
              }
            >
              {g.items.length === 0 ? (
                <EmptyState title="Etapa sem itens" description="Adicione serviços, materiais, mão de obra ou equipamentos." />
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-max text-sm">
                    <caption className="sr-only">Itens da etapa {g.name}</caption>
                    <thead>
                      <tr className="bg-muted/60 text-muted-foreground text-xs uppercase">
                        <th scope="col" className="px-3 py-2 text-left font-medium">Código</th>
                        <th scope="col" className="px-3 py-2 text-left font-medium">Descrição</th>
                        <th scope="col" className="px-3 py-2 text-left font-medium">Tipo</th>
                        <th scope="col" className="px-3 py-2 text-left font-medium">Un.</th>
                        <th scope="col" className="px-3 py-2 text-right font-medium">Qtd.</th>
                        <th scope="col" className="px-3 py-2 text-right font-medium">Preço un.</th>
                        <th scope="col" className="px-3 py-2 text-right font-medium">Total</th>
                        <th scope="col" className="px-3 py-2 text-left font-medium">Ações</th>
                      </tr>
                    </thead>
                    <tbody>
                      {g.items.map((it) => (
                        <tr key={it.id} className="hover:bg-primary-soft/50 border-t">
                          <td className="px-3 py-2 font-medium">{it.code}</td>
                          <td className="max-w-80 px-3 py-2">
                            {it.description}
                            {it.compositionId && (
                              <button type="button" className="text-primary ml-2 text-xs underline" onClick={() => setViewCompId(it.compositionId!)}>
                                ver composição
                              </button>
                            )}
                          </td>
                          <td className="px-3 py-2"><StatusBadge tone="primary">{resourceTypeLabel[it.type]}</StatusBadge></td>
                          <td className="px-3 py-2">{it.unit}</td>
                          <td className="px-3 py-2 text-right tabular-nums">{formatDecimal(it.quantity)}</td>
                          <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(effectiveUnitPrice(it, compositions))}</td>
                          <td className="px-3 py-2 text-right font-medium tabular-nums">{formatCurrency(itemTotal(it, compositions))}</td>
                          <td className="px-3 py-2">
                            <div className="flex">
                              <Button variant="ghost" size="icon" aria-label={`Editar ${it.description}`} onClick={() => openItem(g.id, it)}><Pencil className="size-4" /></Button>
                              <Button variant="ghost" size="icon" aria-label={`Remover ${it.description}`} onClick={() => setRemoving({ groupId: g.id, itemId: it.id })}><Trash2 className="size-4" /></Button>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Panel>
          ))}
        </div>

        <aside aria-label="Resumo do orçamento" className="xl:sticky xl:top-0 xl:self-start">
          <Panel title="Resumo">
            <div className="flex flex-col gap-3 p-4">
              <FormField label="BDI (%)" hint="Percentual demonstrativo sobre o custo direto.">
                <TextInput type="number" min={0} max={100} step="0.01" value={bdi} onChange={(e) => setBdi(Math.max(0, Number(e.target.value) || 0))} />
              </FormField>
              <FormField label="Desconto (%)" hint="Aplicado sobre custo direto + BDI.">
                <TextInput type="number" min={0} max={100} step="0.01" value={discount} onChange={(e) => setDiscount(Math.min(100, Math.max(0, Number(e.target.value) || 0)))} />
              </FormField>
              <MetricCard label="Custo direto" value={formatCurrency(totals.direct)} />
              <MetricCard label={`BDI (${formatDecimal(bdi)}%)`} value={formatCurrency(totals.bdiValue)} />
              <MetricCard label={`Desconto (${formatDecimal(discount)}%)`} value={formatCurrency(totals.discountValue)} />
              <MetricCard label="Valor total" value={formatCurrency(totals.total)} emphasis />
            </div>
          </Panel>
        </aside>
      </div>

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing?.item ? 'Editar item' : 'Adicionar item'}
        footer={<><Button variant="outline" onClick={() => setEditing(null)}>Cancelar</Button><Button onClick={saveItem}>Salvar item</Button></>}
      >
        <form onSubmit={saveItem} noValidate className="grid gap-4 sm:grid-cols-2">
          <FormField label="Composição (opcional)" className="sm:col-span-2" hint="Ao vincular uma composição, o preço unitário passa a vir dela.">
            <SelectInput
              {...form.register('compositionId', {
                onChange: (e) => {
                  const c = compositions.find((x) => x.id === e.target.value)
                  if (c) {
                    form.setValue('code', c.code)
                    form.setValue('description', c.description)
                    form.setValue('unit', c.unit)
                    form.setValue('type', 'service')
                  }
                },
              })}
            >
              <option value="">Sem composição (preço manual)</option>
              {compositions.map((c) => <option key={c.id} value={c.id}>{c.code} — {c.description}</option>)}
            </SelectInput>
          </FormField>
          <FormField label="Código" required error={errors.code?.message}><TextInput {...form.register('code')} /></FormField>
          <FormField label="Tipo" required>
            <SelectInput {...form.register('type')}>
              {(Object.keys(resourceTypeLabel) as ResourceType[]).map((t) => <option key={t} value={t}>{resourceTypeLabel[t]}</option>)}
            </SelectInput>
          </FormField>
          <FormField label="Descrição" required error={errors.description?.message} className="sm:col-span-2"><TextInput {...form.register('description')} /></FormField>
          <FormField label="Unidade" required error={errors.unit?.message}><TextInput {...form.register('unit')} /></FormField>
          <FormField label="Quantidade" required error={errors.quantity?.message}>
            <TextInput type="number" step="any" {...form.register('quantity', { valueAsNumber: true })} />
          </FormField>
          <FormField label="Preço unitário (R$)" error={errors.unitPrice?.message} className="sm:col-span-2">
            <TextInput type="number" step="any" disabled={Boolean(compositionId)} {...form.register('unitPrice', { valueAsNumber: true })} />
          </FormField>
        </form>
      </Modal>

      <Modal
        open={newGroup !== null}
        onClose={() => setNewGroup(null)}
        title="Nova etapa"
        size="md"
        footer={<><Button variant="outline" onClick={() => setNewGroup(null)}>Cancelar</Button><Button onClick={addGroup} disabled={!newGroup?.trim()}>Criar etapa</Button></>}
      >
        <FormField label="Nome da etapa" required>
          <TextInput value={newGroup ?? ''} onChange={(e) => setNewGroup(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && addGroup()} />
        </FormField>
      </Modal>

      <ConfirmDialog
        open={removing !== null}
        title="Remover item?"
        description="O item será removido deste orçamento em edição."
        confirmLabel="Remover"
        destructive
        onConfirm={confirmRemove}
        onCancel={() => setRemoving(null)}
      />
      <CostComposition composition={viewing} onClose={() => setViewCompId(null)} />
    </>
  )
}
