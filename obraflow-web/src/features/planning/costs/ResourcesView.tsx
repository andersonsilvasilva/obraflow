import { ChevronDown, ChevronRight, ChevronsDownUp, ChevronsUpDown, Eye, EyeOff, Plus, Trash2 } from 'lucide-react'
import { Fragment, useState } from 'react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { formatCurrency } from '@/utils/format'
import { costKindLabel, itemSourceLabel, type CostKind, type PlanItem, type PlanTask } from '../api'

const money = (v: string | null | undefined) => (v === null || v === undefined ? '—' : formatCurrency(Number(v)))
const qty = (v: string | null | undefined) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 4 }))

/** Edição em linha de um número (consumo, quantidade): confirma com Enter ou ao sair do campo. */
function NumberCell({ value, disabled, onSave, label }: { value: string | null; disabled: boolean; onSave: (v: string) => void; label: string }) {
  const [editing, setEditing] = useState<string | null>(null)
  if (disabled || editing === null) {
    return (
      <button type="button" disabled={disabled} aria-label={`Editar ${label}`} onClick={() => setEditing(value === null ? '' : String(Number(value)))} className={cn('w-full rounded px-1 text-right tabular-nums', !disabled && 'hover:bg-muted cursor-text')}>
        {qty(value)}
      </button>
    )
  }
  const commit = () => {
    const v = editing.replace(',', '.').trim()
    setEditing(null)
    if (v !== '' && Number(v) >= 0 && Number(v) !== Number(value ?? NaN)) onSave(v)
  }
  return (
    <input
      autoFocus
      aria-label={label}
      inputMode="decimal"
      className="border-primary w-full rounded border px-1 text-right tabular-nums"
      value={editing}
      onChange={(e) => setEditing(e.target.value)}
      onBlur={commit}
      onKeyDown={(e) => (e.key === 'Enter' ? commit() : e.key === 'Escape' ? setEditing(null) : undefined)}
    />
  )
}

interface Props {
  tasks: PlanTask[]
  items: PlanItem[]
  canEdit: boolean
  onAdd: (task: PlanTask) => void
  onUpdateItem: (id: number, body: Record<string, unknown>) => void
  onRemoveItem: (item: PlanItem) => void
  onUpdateTask: (task: PlanTask, body: Record<string, unknown>) => void
}

/**
 * "Visualizar recursos" (6B, D-P95; referência: aba Recursos do Brickup): a árvore de etapas e atividades com os
 * recursos de cada uma. Consumo (Qtd. unit.) e quantidade da atividade editáveis na linha; os totais vêm do servidor.
 */
export function ResourcesView({ tasks, items, canEdit, onAdd, onUpdateItem, onRemoveItem, onUpdateTask }: Props) {
  const [closed, setClosed] = useState<Set<number>>(new Set())
  const [onlyTasks, setOnlyTasks] = useState(false)
  const byTask = new Map<number, PlanItem[]>()
  for (const i of items) byTask.set(i.task_id, [...(byTask.get(i.task_id) ?? []), i])
  const parentOf = new Map(tasks.map((t) => [t.id, t.parent_id]))
  const hidden = (t: PlanTask) => {
    for (let p = t.parent_id; p !== null && p !== undefined; p = parentOf.get(p) ?? null) if (closed.has(p)) return true
    return false
  }
  const depth = (t: PlanTask) => t.wbs.split('.').length - 1
  const toggle = (id: number) => setClosed((s) => (s.has(id) ? new Set([...s].filter((x) => x !== id)) : new Set([...s, id])))
  const withChildren = new Set([...tasks.map((t) => t.parent_id).filter((p): p is number => p !== null), ...items.map((i) => i.task_id)])

  return (
    <div className="rounded-lg border">
      <div className="flex flex-wrap items-center gap-2 border-b p-2">
        <Button size="sm" variant="ghost" onClick={() => setClosed(new Set())}>
          <ChevronsUpDown className="size-3.5" /> Expandir todos
        </Button>
        <Button size="sm" variant="ghost" onClick={() => setClosed(new Set(withChildren))}>
          <ChevronsDownUp className="size-3.5" /> Recolher todos
        </Button>
        <Button size="sm" variant="ghost" aria-pressed={onlyTasks} onClick={() => setOnlyTasks(!onlyTasks)}>
          {onlyTasks ? <Eye className="size-3.5" /> : <EyeOff className="size-3.5" />} {onlyTasks ? 'Exibir recursos' : 'Exibir apenas atividades'}
        </Button>
      </div>
      <div className="max-h-[60vh] overflow-auto">
        <table className="w-full min-w-[980px] text-sm">
          <thead className="bg-muted/60 sticky top-0 z-10 text-left text-xs">
            <tr>
              <th className="px-2 py-2 font-medium">Nome</th>
              <th className="px-2 py-2 font-medium">Tipo</th>
              <th className="px-2 py-2 font-medium">Un.</th>
              <th className="w-24 px-2 py-2 text-right font-medium">Qtd. unit.</th>
              <th className="w-24 px-2 py-2 text-right font-medium">Qtd. total</th>
              <th className="px-2 py-2 text-right font-medium">Venda unit.</th>
              <th className="px-2 py-2 text-right font-medium">Custo unit.</th>
              <th className="px-2 py-2 text-right font-medium">Total de venda</th>
              <th className="px-2 py-2 text-right font-medium">Total de custo</th>
              <th className="w-16 px-2 py-2" aria-label="Ações" />
            </tr>
          </thead>
          <tbody>
            {tasks
              .filter((t) => !hidden(t))
              .map((t) => {
                const own = byTask.get(t.id) ?? []
                const open = !closed.has(t.id)
                const isTask = t.kind === 'task'
                const pad = { paddingLeft: `${depth(t) * 16 + 8}px` }
                return (
                  <Fragment key={t.id}>
                    <tr className={cn('border-t', t.kind === 'summary' ? 'bg-primary-soft/40 font-medium' : 'hover:bg-muted/30')}>
                      <td className="px-2 py-1.5" style={pad}>
                        <span className="inline-flex items-center gap-1">
                          {withChildren.has(t.id) ? (
                            <button type="button" className="text-muted-foreground" aria-label={open ? 'Recolher' : 'Expandir'} onClick={() => toggle(t.id)}>
                              {open ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
                            </button>
                          ) : (
                            <span className="inline-block w-3.5" />
                          )}
                          <span className="text-muted-foreground mr-1 font-mono text-xs">{t.wbs}</span>
                          {t.name}
                        </span>
                      </td>
                      <td className="px-2 py-1.5">{t.kind === 'summary' ? 'Etapa' : t.kind === 'milestone' ? 'Marco' : 'Atividade'}</td>
                      <td className="px-2 py-1.5">{t.unit_code ?? '—'}</td>
                      <td className="px-2 py-1.5">
                        {isTask ? <NumberCell label={`quantidade de ${t.name}`} value={t.quantity} disabled={!canEdit} onSave={(v) => onUpdateTask(t, { quantity: v })} /> : null}
                      </td>
                      <td className="px-2 py-1.5 text-right tabular-nums">{isTask ? qty(t.quantity ?? '1') : ''}</td>
                      <td className="px-2 py-1.5 text-right tabular-nums">{isTask ? money(t.cost?.unit_sale) : ''}</td>
                      <td className="px-2 py-1.5 text-right tabular-nums">{isTask ? money(t.cost?.unit_cost) : ''}</td>
                      <td className="px-2 py-1.5 text-right tabular-nums">{money(t.cost?.sale)}</td>
                      <td className="px-2 py-1.5 text-right tabular-nums">{money(t.cost?.cost)}</td>
                      <td className="px-2 py-1.5 text-right">
                        {isTask && canEdit && (
                          <button type="button" className="text-muted-foreground hover:text-primary" aria-label={`Adicionar recurso em ${t.name}`} title="Adicionar recurso" onClick={() => onAdd(t)}>
                            <Plus className="size-4" />
                          </button>
                        )}
                      </td>
                    </tr>
                    {!onlyTasks &&
                      open &&
                      own.map((i) => (
                        <tr key={`i${i.id}`} className="text-muted-foreground border-t text-[0.8125rem]">
                          <td className="px-2 py-1" style={{ paddingLeft: `${depth(t) * 16 + 36}px` }}>
                            <span className="text-foreground">{i.description}</span>
                            <span className="ml-2 text-xs">{itemSourceLabel[i.source]}</span>
                          </td>
                          <td className="px-2 py-1">{i.kind === 'composition' ? 'Composição' : costKindLabel[i.kind as CostKind]}</td>
                          <td className="px-2 py-1">{i.unit_code}</td>
                          <td className="px-2 py-1">
                            <NumberCell label={`consumo de ${i.description}`} value={i.quantity_per_unit} disabled={!canEdit} onSave={(v) => onUpdateItem(i.id, { quantity_per_unit: v })} />
                          </td>
                          <td className="px-2 py-1 text-right tabular-nums">{qty(i.computed.quantity_total)}</td>
                          <td className="px-2 py-1 text-right tabular-nums">{money(i.computed.unit_sale)}</td>
                          <td className="px-2 py-1 text-right tabular-nums">{money(i.unit_cost)}</td>
                          <td className="px-2 py-1 text-right tabular-nums">{money(i.computed.sale)}</td>
                          <td className="px-2 py-1 text-right tabular-nums">{money(i.computed.cost)}</td>
                          <td className="px-2 py-1 text-right">
                            {canEdit && (
                              <button type="button" className="text-muted-foreground hover:text-destructive" aria-label={`Excluir ${i.description}`} title="Excluir recurso" onClick={() => onRemoveItem(i)}>
                                <Trash2 className="size-3.5" />
                              </button>
                            )}
                          </td>
                        </tr>
                      ))}
                  </Fragment>
                )
              })}
          </tbody>
        </table>
        {tasks.length === 0 && <p className="text-muted-foreground p-4 text-sm">Nenhuma atividade ainda.</p>}
      </div>
    </div>
  )
}
