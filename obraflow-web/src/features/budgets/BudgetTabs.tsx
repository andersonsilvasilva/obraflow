import { useMutation, useQuery } from '@tanstack/react-query'
import { Calculator, FolderOpen, Pencil, Plus, Trash2 } from 'lucide-react'
import { Fragment, useState, type ReactNode } from 'react'
import { StatusBadge } from '@/components/data-display/indicators'
import { Alert } from '@/components/feedback/Alert'
import { Modal } from '@/components/feedback/Modal'
import { EmptyState, ErrorState, LoadingState } from '@/components/feedback/states'
import { FormField, SelectInput, TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { getErrorMessage } from '@/lib/api-error'
import { cn } from '@/lib/utils'
import { formatCurrency } from '@/utils/format'
import { budgetsApi, budgetStatusLabel, type Group, type Item, type Version } from './api'

const money = (v: string | null | undefined) => (v === null || v === undefined ? '—' : formatCurrency(Number(v)))
const num = (v: string, digits = 4) => Number(v).toLocaleString('pt-BR', { maximumFractionDigits: digits })
const pct = (v: string | null) => (v === null ? '—' : `${Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 4 })}%`)

/** Etapas em ordem de árvore com profundidade. */
export function orderedGroups(groups: Group[]): { group: Group; depth: number }[] {
  const out: { group: Group; depth: number }[] = []
  const walk = (parent: number | null, depth: number) =>
    groups
      .filter((g) => g.parent_id === parent)
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
      .forEach((g) => {
        out.push({ group: g, depth })
        walk(g.id, depth + 1)
      })
  walk(null, 0)
  return out
}

// ================================================================== Planilha (EAP + itens)
export function SheetTab({
  version,
  editable,
  onAddGroup,
  onEditGroup,
  onDeleteGroup,
  onAddItem,
  onAddReference,
  onEditItem,
  onDeleteItem,
  onComposition,
}: {
  version: Version
  editable: boolean
  onAddGroup: () => void
  onEditGroup: (g: Group) => void
  onDeleteGroup: (g: Group) => void
  onAddItem: () => void
  onAddReference: () => void
  onEditItem: (i: Item) => void
  onDeleteItem: (i: Item) => void
  onComposition: (i: Item) => void
}) {
  const tree = orderedGroups(version.groups)
  const itemsOf = (g: number) => version.items.filter((i) => i.group_id === g)
  const lockHint = editable ? undefined : 'Versão bloqueada ou fora de rascunho'

  return (
    <section aria-label="Planilha do orçamento">
      <div className="mb-3 flex flex-wrap justify-end gap-2">
        <Button variant="outline" onClick={onAddGroup} disabled={!editable} title={lockHint}>
          <Plus className="size-4" /> Etapa
        </Button>
        <Button variant="outline" onClick={onAddItem} disabled={!editable || tree.length === 0} title={lockHint}>
          <Plus className="size-4" /> Item manual
        </Button>
        <Button onClick={onAddReference} disabled={!editable || tree.length === 0} title={lockHint}>
          <Plus className="size-4" /> Da base de referência
        </Button>
      </div>
      {tree.length === 0 ? (
        <EmptyState title="Orçamento vazio" description='Comece criando uma etapa (ex.: "1 — Serviços preliminares").' />
      ) : (
        <div className="max-h-[calc(100dvh-22rem)] overflow-auto rounded-lg border">
          <table className="w-full min-w-max text-sm">
            <caption className="sr-only">Etapas e itens</caption>
            <thead className="bg-muted sticky top-0 z-[1]">
              <tr className="text-left">
                {['Código', 'Descrição', 'Un.', 'Quant.', 'Custo unit.', 'BDI', 'Desc.', 'Custo total', 'Preço total'].map((h, i) => (
                  <th key={h} scope="col" className={cn('px-3 py-2 font-semibold', i >= 3 && 'text-right')}>
                    {h}
                  </th>
                ))}
                <th scope="col" className="px-3 py-2">
                  <span className="sr-only">Ações</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {tree.map(({ group, depth }) => (
                <Fragment key={group.id}>
                  <tr className="bg-primary-soft/40 border-b font-semibold">
                    <td className="px-3 py-2" style={{ paddingLeft: `${0.75 + depth}rem` }}>
                      {group.code}
                    </td>
                    <td className="px-3 py-2" colSpan={6}>
                      {group.name}
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums">{money(version.totals.groups[group.id]?.cost)}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{money(version.totals.groups[group.id]?.sale)}</td>
                    <td className="px-2 py-1">
                      <RowButtons editable={editable} onEdit={() => onEditGroup(group)} onDelete={() => onDeleteGroup(group)} />
                    </td>
                  </tr>
                  {itemsOf(group.id).map((it) => (
                    <tr key={it.id} className="hover:bg-primary-soft/30 border-b">
                      <td className="px-3 py-2 font-mono text-xs" style={{ paddingLeft: `${1.25 + depth}rem` }}>
                        {it.code}
                      </td>
                      <td className="max-w-[28rem] px-3 py-2">
                        <span className="line-clamp-2">{it.description}</span>
                        {it.source !== 'manual' && (
                          <span className="text-muted-foreground text-xs">
                            {it.source === 'official' ? 'Oficial' : 'Ajustado'}
                            {it.source_ref && ` · ${it.source_ref.source} ${it.source_ref.code} · ${it.source_ref.state} ${it.source_ref.competency}`}
                          </span>
                        )}
                      </td>
                      <td className="px-3 py-2">{it.unit_code}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{num(it.quantity)}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{money(it.unit_cost)}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{it.bdi_percent === null ? <span className="text-muted-foreground">herdado</span> : pct(it.bdi_percent)}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{Number(it.discount_percent) ? pct(it.discount_percent) : '—'}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{money(it.total_cost)}</td>
                      <td className="px-3 py-2 text-right font-medium tabular-nums">{money(it.total_sale)}</td>
                      <td className="px-2 py-1">
                        <RowButtons
                          editable={editable}
                          onEdit={() => onEditItem(it)}
                          onDelete={() => onDeleteItem(it)}
                          extra={
                            it.source !== 'manual' ? (
                              <Button variant="ghost" size="icon" aria-label="Composição e insumos" title="Composição e insumos" onClick={() => onComposition(it)}>
                                <FolderOpen className="size-4" />
                              </Button>
                            ) : null
                          }
                        />
                      </td>
                    </tr>
                  ))}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

function RowButtons({ editable, onEdit, onDelete, extra }: { editable: boolean; onEdit: () => void; onDelete: () => void; extra?: ReactNode }) {
  return (
    <span className="flex justify-end gap-1">
      {extra}
      <Button variant="ghost" size="icon" aria-label="Editar" disabled={!editable} onClick={onEdit}>
        <Pencil className="size-4" />
      </Button>
      <Button variant="ghost" size="icon" aria-label="Excluir" disabled={!editable} onClick={onDelete}>
        <Trash2 className="size-4" />
      </Button>
    </span>
  )
}

// ================================================================== BDI e custos indiretos
const TCU = [
  ['ac', 'Administração central'],
  ['s', 'Seguro'],
  ['r', 'Risco'],
  ['g', 'Garantia'],
  ['df', 'Despesas financeiras'],
  ['l', 'Lucro'],
  ['i', 'Tributos (PIS, COFINS, ISS…)'],
] as const

export function BdiTab({ uuid, version, editable, onChanged }: { uuid: string; version: Version; editable: boolean; onChanged: () => void }) {
  const global = version.bdi.find((b) => b.scope === 'global')
  const [method, setMethod] = useState<'informed' | 'tcu'>(global?.method ?? 'informed')
  const [percent, setPercent] = useState(global?.method === 'informed' ? Number(global.percent).toString() : '')
  const [comps, setComps] = useState<Record<string, string>>(global?.components ?? {})
  const [discount, setDiscount] = useState(Number(version.discount_percent).toString())
  const [ind, setInd] = useState({ description: '', method: 'fixed', value: '' })
  const [error, setError] = useState<string | null>(null)
  const run = (fn: () => Promise<unknown>) =>
    fn()
      .then(() => (setError(null), onChanged()))
      .catch((e) => setError(getErrorMessage(e)))

  const saveBdi = () =>
    run(() =>
      budgetsApi.bdi(uuid, version.number, method === 'informed'
        ? { scope: 'global', method, percent: percent.replace(',', '.') }
        : { scope: 'global', method, components: Object.fromEntries(Object.entries(comps).map(([k, v]) => [k, v.replace(',', '.') || '0'])) }),
    )

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      {error && (
        <Alert tone="danger" className="lg:col-span-2">
          {error}
        </Alert>
      )}
      <section aria-label="BDI global" className="rounded-lg border p-4">
        <h2 className="mb-1 font-semibold">BDI global</h2>
        <p className="text-muted-foreground mb-3 text-sm">
          Vale para todos os itens, exceto os que têm BDI da etapa ou do próprio item. Atual: <strong>{global ? `${Number(global.percent).toLocaleString('pt-BR')}%` : 'não definido (0%)'}</strong>
        </p>
        <div className="mb-3 flex gap-4" role="radiogroup" aria-label="Método">
          {(['informed', 'tcu'] as const).map((m) => (
            <label key={m} className="flex items-center gap-2 text-sm">
              <input type="radio" className="accent-(--primary) size-4" checked={method === m} onChange={() => setMethod(m)} disabled={!editable} />
              {m === 'informed' ? 'Percentual informado' : 'Fórmula do TCU'}
            </label>
          ))}
        </div>
        {method === 'informed' ? (
          <FormField label="BDI (%)">
            <TextInput inputMode="decimal" value={percent} onChange={(e) => setPercent(e.target.value)} disabled={!editable} />
          </FormField>
        ) : (
          <>
            <Alert tone="info" className="mb-3">
              BDI = [(1+AC+S+R+G)·(1+DF)·(1+L)/(1−I)] − 1 (Acórdão TCU 2622/2013). Validar o uso em obra privada é decisão da empresa.
            </Alert>
            <div className="grid gap-3 sm:grid-cols-2">
              {TCU.map(([k, label]) => (
                <FormField key={k} label={`${label} (%)`}>
                  <TextInput inputMode="decimal" value={comps[k] ?? ''} onChange={(e) => setComps({ ...comps, [k]: e.target.value })} disabled={!editable} />
                </FormField>
              ))}
            </div>
          </>
        )}
        <div className="mt-3 flex gap-2">
          <Button onClick={saveBdi} disabled={!editable}>
            <Calculator className="size-4" /> {method === 'tcu' ? 'Calcular e salvar' : 'Salvar'}
          </Button>
          {global && (
            <Button variant="outline" disabled={!editable} onClick={() => run(() => budgetsApi.bdi(uuid, version.number, { scope: 'global', method: 'informed', remove: true }))}>
              Remover
            </Button>
          )}
        </div>
        {version.bdi.filter((b) => b.scope !== 'global').length > 0 && (
          <div className="mt-4">
            <h3 className="mb-1 text-sm font-semibold">BDI por etapa/item</h3>
            <ul className="text-sm">
              {version.bdi
                .filter((b) => b.scope !== 'global')
                .map((b) => {
                  const label = b.scope === 'group' ? version.groups.find((g) => g.id === b.group_id)?.name : version.items.find((i) => i.id === b.item_id)?.code
                  return (
                    <li key={b.id} className="flex items-center justify-between border-b py-1">
                      <span>
                        {b.scope === 'group' ? 'Etapa' : 'Item'} {label}: <strong>{Number(b.percent).toLocaleString('pt-BR')}%</strong> {b.method === 'tcu' && '(TCU)'}
                      </span>
                      <Button
                        variant="ghost"
                        size="sm"
                        disabled={!editable}
                        onClick={() => run(() => budgetsApi.bdi(uuid, version.number, { scope: b.scope, group_id: b.group_id, item_id: b.item_id, method: 'informed', remove: true }))}
                      >
                        Remover
                      </Button>
                    </li>
                  )
                })}
            </ul>
          </div>
        )}
      </section>

      <section aria-label="Custos indiretos e desconto" className="rounded-lg border p-4">
        <h2 className="mb-1 font-semibold">Custos indiretos</h2>
        <p className="text-muted-foreground mb-3 text-sm">Linhas próprias, fora do BDI: valor fixo ou percentual do custo direto.</p>
        <ul className="mb-3 divide-y text-sm">
          {version.indirect_costs.map((c) => (
            <li key={c.id} className="flex items-center justify-between py-1.5">
              <span>
                {c.description} — <strong>{c.method === 'fixed' ? money(c.amount) : `${Number(c.percent).toLocaleString('pt-BR')}% do custo direto`}</strong>
              </span>
              <Button variant="ghost" size="icon" aria-label={`Excluir ${c.description}`} disabled={!editable} onClick={() => run(() => budgetsApi.indirect.remove(uuid, version.number, c.id))}>
                <Trash2 className="size-4" />
              </Button>
            </li>
          ))}
          {version.indirect_costs.length === 0 && <li className="text-muted-foreground py-1.5">Nenhum custo indireto.</li>}
        </ul>
        {editable && (
          <form
            className="grid gap-2 sm:grid-cols-[1fr_9rem_7rem_auto]"
            onSubmit={(e) => {
              e.preventDefault()
              run(() =>
                budgetsApi.indirect.create(uuid, version.number, {
                  description: ind.description,
                  method: ind.method,
                  [ind.method === 'fixed' ? 'amount' : 'percent']: ind.value.replace(',', '.'),
                }),
              ).then(() => setInd({ description: '', method: 'fixed', value: '' }))
            }}
          >
            <TextInput aria-label="Descrição" placeholder="Ex.: Canteiro" value={ind.description} onChange={(e) => setInd({ ...ind, description: e.target.value })} />
            <SelectInput aria-label="Método" value={ind.method} onChange={(e) => setInd({ ...ind, method: e.target.value })}>
              <option value="fixed">Valor (R$)</option>
              <option value="percent_of_direct">% do direto</option>
            </SelectInput>
            <TextInput aria-label="Valor" inputMode="decimal" value={ind.value} onChange={(e) => setInd({ ...ind, value: e.target.value })} />
            <Button type="submit" disabled={!ind.description || !ind.value}>
              Adicionar
            </Button>
          </form>
        )}

        <h2 className="mt-6 mb-1 font-semibold">Desconto global</h2>
        <p className="text-muted-foreground mb-2 text-sm">Sobre o preço de venda (itens + indiretos).</p>
        <div className="flex gap-2">
          <TextInput aria-label="Desconto global (%)" inputMode="decimal" className="w-32" value={discount} onChange={(e) => setDiscount(e.target.value)} disabled={!editable} />
          <Button variant="outline" disabled={!editable} onClick={() => run(() => budgetsApi.updateVersion(uuid, version.number, { discount_percent: discount.replace(',', '.') || '0' }))}>
            Salvar
          </Button>
        </div>
      </section>
    </div>
  )
}

// ================================================================== Curva ABC
export function AbcTab({ uuid, number }: { uuid: string; number: number }) {
  const [type, setType] = useState<'services' | 'resources'>('services')
  const q = useQuery({ queryKey: ['budget-abc', uuid, number, type], queryFn: () => budgetsApi.abc(uuid, number, type) })
  const tone = { A: 'danger', B: 'warning', C: 'success' } as const

  return (
    <section aria-label="Curva ABC">
      <div className="mb-3 flex items-center gap-2" role="tablist" aria-label="Tipo">
        {(['services', 'resources'] as const).map((t) => (
          <Button key={t} role="tab" aria-selected={type === t} variant={type === t ? 'default' : 'outline'} onClick={() => setType(t)}>
            {t === 'services' ? 'Serviços' : 'Insumos'}
          </Button>
        ))}
        <span className="text-muted-foreground ml-auto text-xs">A: até 80% do valor acumulado · B: até 95% · C: restante</span>
      </div>
      {q.isPending ? (
        <LoadingState />
      ) : q.isError ? (
        <ErrorState error={q.error} />
      ) : q.data.length === 0 ? (
        <EmptyState title="Sem itens para a curva." />
      ) : (
        <div className="max-h-[calc(100dvh-22rem)] overflow-auto rounded-lg border">
          <table className="w-full min-w-max text-sm">
            <caption className="sr-only">Curva ABC de {type === 'services' ? 'serviços' : 'insumos'}</caption>
            <thead className="bg-muted sticky top-0">
              <tr className="text-left">
                {['Código', 'Descrição', 'Un.', 'Quant.', 'Valor unit.', 'Total', '%', '% acum.', 'Faixa'].map((h, i) => (
                  <th key={h} scope="col" className={cn('px-3 py-2 font-semibold', i >= 3 && i < 8 && 'text-right')}>
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {q.data.map((r) => (
                <tr key={r.code} className="border-b">
                  <td className="px-3 py-2 font-mono text-xs">{r.code}</td>
                  <td className="max-w-[28rem] px-3 py-2">
                    <span className="line-clamp-2">{r.description}</span>
                  </td>
                  <td className="px-3 py-2">{r.unit}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{num(r.quantity)}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{money(r.unit_value)}</td>
                  <td className="px-3 py-2 text-right font-medium tabular-nums">{money(r.total)}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{num(r.percent, 2)}%</td>
                  <td className="px-3 py-2 text-right tabular-nums">{num(r.cumulative_percent, 2)}%</td>
                  <td className="px-3 py-2">
                    <StatusBadge tone={tone[r.class]}>{r.class}</StatusBadge>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

// ================================================================== Histórico
export function HistoryTab({ uuid }: { uuid: string }) {
  const q = useQuery({ queryKey: ['budget-history', uuid], queryFn: () => budgetsApi.history(uuid) })
  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState error={q.error} />
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <section aria-label="Mudanças de status">
        <h2 className="mb-2 font-semibold">Status</h2>
        {q.data.status.length === 0 ? (
          <p className="text-muted-foreground text-sm">Nenhuma mudança ainda.</p>
        ) : (
          <ol className="flex flex-col gap-3">
            {q.data.status.map((h, i) => (
              <li key={i} className="border-primary border-l-2 pl-3 text-sm">
                <p>
                  v{h.version}: {h.from ? budgetStatusLabel[h.from] : '—'} → <strong>{budgetStatusLabel[h.to]}</strong>
                </p>
                <p className="text-muted-foreground text-xs">
                  {new Date(h.at).toLocaleString('pt-BR')} · {h.by?.name ?? '—'}
                </p>
                {h.reason && <p className="mt-0.5">{h.reason}</p>}
              </li>
            ))}
          </ol>
        )}
      </section>
      <section aria-label="Decisões">
        <h2 className="mb-2 font-semibold">Decisões</h2>
        {q.data.decisions.length === 0 ? (
          <p className="text-muted-foreground text-sm">Nenhuma decisão ainda.</p>
        ) : (
          <ul className="flex flex-col gap-3">
            {q.data.decisions.map((d, i) => (
              <li key={i} className="rounded-lg border p-3 text-sm">
                <StatusBadge tone={d.decision === 'approved' ? 'success' : 'danger'}>{d.decision === 'approved' ? 'Aprovada' : 'Rejeitada'}</StatusBadge>{' '}
                v{d.version} · {new Date(d.at).toLocaleString('pt-BR')} · {d.by?.name}
                {d.comment && <p className="mt-1">{d.comment}</p>}
                <p className="text-muted-foreground mt-1 font-mono text-[11px] break-all" title="SHA-256 dos totais no momento da decisão">
                  {d.totals_hash}
                </p>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}

// ================================================================== Composição do item
export function CompositionModal({ uuid, number, item, onClose }: { uuid: string; number: number; item: Item; onClose: () => void }) {
  const q = useQuery({ queryKey: ['budget-composition', uuid, number, item.id], queryFn: () => budgetsApi.items.composition(uuid, number, item.id) })
  return (
    <Modal open onClose={onClose} title={`${item.code} — composição e insumos`} description={item.description} size="xl">
      {q.isPending ? (
        <LoadingState />
      ) : q.isError ? (
        <ErrorState error={q.error} />
      ) : (
        <div className="flex flex-col gap-5">
          {q.data.composition && (
            <section aria-label="Itens da composição">
              <p className="text-muted-foreground mb-2 text-xs">
                Origem: {q.data.composition.source_snapshot.source} · {q.data.composition.source_snapshot.state} {q.data.composition.source_snapshot.competency} ·{' '}
                {q.data.composition.source_snapshot.regime === 'with_relief' ? 'desonerado' : 'não desonerado'} · custo publicado {money(q.data.composition.unit_cost)}/{q.data.composition.unit_code}
              </p>
              <SmallTable
                caption="Itens diretos"
                head={['Tipo', 'Código', 'Descrição', 'Un.', 'Coeficiente', 'Preço', 'Custo']}
                rows={q.data.composition.items.map((c) => [
                  c.item_type === 'composition' ? 'Composição' : 'Insumo',
                  c.code,
                  c.description,
                  c.unit_code,
                  c.coefficient,
                  money(c.unit_price),
                  money(c.cost),
                ])}
              />
            </section>
          )}
          <section aria-label="Insumos finais">
            <h3 className="mb-1 text-sm font-semibold">Insumos finais (por unidade do item)</h3>
            <SmallTable
              caption="Insumos finais"
              head={['Código', 'Descrição', 'Un.', 'Coeficiente', 'Preço']}
              rows={q.data.resources.map((r) => [r.code, r.description, r.unit_code, r.coefficient, money(r.unit_price)])}
            />
          </section>
        </div>
      )}
    </Modal>
  )
}

function SmallTable({ caption, head, rows }: { caption: string; head: string[]; rows: ReactNode[][] }) {
  return (
    <div className="max-h-64 overflow-auto rounded-lg border">
      <table className="w-full text-xs">
        <caption className="sr-only">{caption}</caption>
        <thead className="bg-muted sticky top-0">
          <tr className="text-left">
            {head.map((h) => (
              <th key={h} scope="col" className="px-2 py-1.5 font-semibold">
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i} className="border-b last:border-0">
              {r.map((c, j) => (
                <td key={j} className="px-2 py-1.5">
                  {c}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export function RejectDialog({ onConfirm, onClose }: { onConfirm: (comment: string) => Promise<unknown>; onClose: () => void }) {
  const [comment, setComment] = useState('')
  const m = useMutation({ mutationFn: () => onConfirm(comment) })
  return (
    <Modal
      open
      onClose={onClose}
      title="Rejeitar versão"
      size="md"
      footer={
        <>
          <Button variant="outline" size="lg" onClick={onClose}>
            Cancelar
          </Button>
          <Button variant="destructive" size="lg" disabled={!comment.trim() || m.isPending} onClick={() => m.mutate()}>
            Rejeitar
          </Button>
        </>
      }
    >
      <FormField label="Motivo" required hint="Fica no histórico e na auditoria.">
        <TextInput value={comment} onChange={(e) => setComment(e.target.value)} autoFocus />
      </FormField>
    </Modal>
  )
}
